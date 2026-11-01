<?php
/**
 * DaemonLockManager — daemon_write.php 的锁与状态恢复管理器
 *
 * 审计 2026-08-13 #2：从 api/daemon_write.php 拆分而来。
 * 原文件 305-510 行 200 余行嵌套流（锁获取 / 死锁接管 / 残留锁检查 /
 * shutdown 恢复 / 认领信息写入 / 锁释放）耦合在主流程中，可读性与可测试性差。
 *
 * 本类封装以下职责：
 *   - flock 原子锁获取与死锁接管（锁年龄 > 阈值时等待有限时间重试）
 *   - 残留锁中记录的章节完成状态检查（进程意外终止但 Lock 残留场景）
 *   - shutdown 函数状态恢复（认领章节回退为 outlined，小说回退为 paused）
 *   - 认领信息与令牌的锁文件写入
 *   - 锁资源的幂等释放（可重复调用）
 *
 * 不变量：
 *   - 一旦 stateFinalized=true，shutdown 不再恢复状态（已正常退出）
 *   - claimedChapterId>0 时 shutdown 才尝试恢复（未认领无需恢复）
 *   - 锁文件为稳定路径，绝不 unlink（由 daemon_lock_lifecycle_static_test 守护）
 */

defined('APP_LOADED') or die('Direct access denied.');

class DaemonLockManager
{
    /** 死锁接管阈值（秒）：锁文件年龄超过此值才视为可能死锁 */
    private const DEADLOCK_THRESHOLD = 600;
    /** 死锁接管最长等待时间（秒）：每秒轮询一次 flock */
    private const TAKEOVER_WAIT = 30;

    private int $novelId;
    private string $lockFile;

    /** @var resource|null 锁文件句柄；null 表示未持有或已释放 */
    private $lockFp = null;

    /** 当前认领的章节 ID（>0 表示已认领，shutdown 时需回退为 outlined） */
    public int $claimedChapterId = 0;
    /** 当前认领的章节编号（用于 clearPostProcessPending 与日志） */
    public int $claimedChapterNumber = 0;
    /** 当前认领令牌；shutdown 只能释放这一代、且仍非 Canon 的认领 */
    public string $claimedChapterToken = '';
    /** 与认领令牌同时冻结的章节基态；shutdown 不从 live 行猜恢复状态 */
    public array $claimedChapterSnapshot = [];
    /** 状态是否已正常终结（true 时 shutdown 跳过状态恢复） */
    public bool $stateFinalized = false;

    public function __construct(int $novelId, string $lockDir)
    {
        $this->novelId  = $novelId;
        $this->lockFile = rtrim($lockDir, '/') . "/daemon_lock_{$novelId}.tmp";
    }

    /**
     * 尝试获取独占锁。
     *
     * @return null 成功
     *              失败时返回完整的 JSON 响应体数组（含 ok/msg/skipped/lock_age），
     *              调用方直接 json_encode 并 exit。
     */
    public function acquire(): ?array
    {
        $this->lockFp = @fopen($this->lockFile, 'c');
        if (!$this->lockFp) {
            return ['ok' => false, 'msg' => '无法创建 Lock 文件'];
        }

        if (flock($this->lockFp, LOCK_EX | LOCK_NB)) {
            return null; // 获取成功
        }

        // 获取失败：判断是死锁还是正常占用
        $lockAge = file_exists($this->lockFile) ? (time() - filemtime($this->lockFile)) : 0;

        if ($lockAge <= self::DEADLOCK_THRESHOLD) {
            // 正常占用中：跳过本次
            @fclose($this->lockFp);
            $this->lockFp = null;
            return [
                'ok'       => false,
                'msg'      => "上一章仍在写作中（Lock 已持续 {$lockAge} 秒），跳过本次",
                'skipped'  => true,
                'lock_age' => $lockAge,
            ];
        }

        // 死锁接管：锁年龄超过阈值，原持有进程可能已崩溃
        addLog($this->novelId, 'daemon_unlock', "检测到死锁（已持续 {$lockAge}s > " . self::DEADLOCK_THRESHOLD . "s），等待接管");
        @fclose($this->lockFp);
        $this->lockFp = @fopen($this->lockFile, 'c');
        if (!$this->lockFp) {
            return ['ok' => false, 'msg' => '无法创建 Lock 文件'];
        }

        $waitStart = time();
        $acquired  = false;
        while (time() - $waitStart < self::TAKEOVER_WAIT) {
            if (flock($this->lockFp, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }
            sleep(1);
        }
        if (!$acquired) {
            @fclose($this->lockFp);
            $this->lockFp = null;
            return ['ok' => false, 'msg' => '等待锁超时，跳过本次'];
        }

        addLog($this->novelId, 'daemon_unlock', '死锁接管成功（等待' . (time() - $waitStart) . '秒）');
        // 锁文件年龄只能证明锁可能失效，不能证明所有 writing 都已僵死；
        // 仍按 DB 心跳阈值逐章恢复，避免误重置活跃章节。
        WriteEngine::recoverStaleWritingChapters($this->novelId);
        return null;
    }

    /**
     * 检查残留锁中记录的章节是否已完成（进程意外终止但 Lock 残留）。
     * 若已完成则记录日志，允许继续执行（不死锁自身）。
     */
    public function inspectResidualChapterLock(): void
    {
        if (!$this->lockFp) {
            return;
        }
        $lockContent = @stream_get_contents($this->lockFp);
        if ($lockContent && preg_match('/chapter#(\d+)/', $lockContent, $m)) {
            $lockedChId = (int)$m[1];
            $lockedCh   = DB::fetch("SELECT status FROM chapters WHERE id=?", [$lockedChId]);
            if ($lockedCh && $lockedCh['status'] === 'completed') {
                addLog($this->novelId, 'daemon_unlock', "章节#{$lockedChId} 已完成但 Lock 残留，继续执行");
            }
        }
    }

    /**
     * 写入认领信息到锁文件（chapter ID + PID + 时间戳）。
     * 已持有 flock，直接 ftruncate + rewrite 即可。
     */
    public function writeClaimContent(int $chapterId): void
    {
        if (!$this->lockFp) {
            return;
        }
        ftruncate($this->lockFp, 0);
        rewind($this->lockFp);
        fwrite($this->lockFp, date('Y-m-d H:i:s') . " chapter#{$chapterId} PID:" . getmypid());
        fflush($this->lockFp);
    }

    /**
     * 注册 shutdown 函数：进程 fatal/异常终止时恢复认领状态。
     * 必须在认领成功后调用（claimedChapterId 已设置）。
     *
     * shutdown 是 finally 的最后一道保险：
     *   - 若 stateFinalized=false 且 claimedChapterId>0：只按原始 claim token 和冻结基态
     *     把仍非 Canon 的章节从 writing 恢复为 outlined/skipped/failed；已提交 Canon
     *     或后继重写认领绝不回退。
     *   - 只有章节 CAS 确实成功时，才恢复小说状态并清理 postProcess pending 标记。
     *   - 始终释放锁资源（若尚未手动释放）。
     *   - WHERE 同时限定 token / status / canonical_state，保证不覆盖已提交正文。
     */
    public function registerShutdownHandler(): void
    {
        $manager = $this; // 闭包捕获对象引用，属性变化对闭包可见
        register_shutdown_function(static function () use ($manager): void {
            if (!$manager->stateFinalized
                && $manager->claimedChapterId > 0
                && preg_match('/^[a-f0-9]{32}$/D', $manager->claimedChapterToken) === 1) {
                try {
                    $restored = WriteEngine::releaseChapterClaimAndPauseNovel(
                        $manager->novelId,
                        $manager->claimedChapterId,
                        [
                            'claim_token' => $manager->claimedChapterToken,
                            '_base_snapshot' => $manager->claimedChapterSnapshot,
                        ]
                    );
                    if ($restored) {
                        WriteEngine::clearPostProcessPending($manager->novelId, $manager->claimedChapterNumber);
                        clearNovelCache($manager->novelId);
                        addLog($manager->novelId, 'daemon_recover', "挂机进程异常退出，已恢复第{$manager->claimedChapterNumber}章为待写状态");
                    }
                } catch (\Throwable $e) {
                    error_log('daemon_write shutdown recovery failed: ' . $e->getMessage());
                }
            }
            $manager->release();
        });
    }

    /**
     * 释放锁资源。可重复调用（已释放则空操作）。
     */
    public function release(): void
    {
        if (is_resource($this->lockFp)) {
            @flock($this->lockFp, LOCK_UN);
            @fclose($this->lockFp);
        }
        $this->lockFp = null;
    }

    /**
     * 锁文件路径（供外部 file_exists 检查，如 status 查询）。
     */
    public function lockFilePath(): string
    {
        return $this->lockFile;
    }
}
