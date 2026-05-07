<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * PIDController — 工程控制论 PID 整定器
 *
 * 钱学森《工程控制论》核心思想：
 *   比例(P) + 积分(I) + 微分(D) = 智能控制
 *
 * 为 Agent 决策系统引入 PID 整定思想：
 *   - P 项：当前偏差越大，纠正力度越大（已有）
 *   - I 项：长期偏差累积，防止"一直在偏"没人管（新增）
 *   - D 项：偏差变化率，防止纠正过冲后反向震荡（新增）
 *
 * 整定对象（核心控制变量）：
 *   1. emotion_score — 情绪密度 (0-100, 目标 75)
 *   2. cool_point_density — 爽点密度 (章/爽点, 目标 3.5)
 *   3. word_count_accuracy — 字数准确率 (0-1, 目标 0.85)
 *   4. quality_score — 质量分数 (0-100, 目标 80)
 *
 * 用法：
 *   $pid = new PIDController($novelId);
 *   $pid->loadState('emotion_score');  // 从DB恢复历史状态
 *   $correction = $pid->compute('emotion_score', $currentValue, 75);
 *   $pid->saveState('emotion_score');
 *   // $correction['p_correction'] / $correction['i_correction'] / $correction['d_correction']
 */

class PIDController
{
    /** @var int */
    private int $novelId;

    /** @var array{kp: float, ki: float, kd: float} 各控制变量的 PID 系数 */
    private const PID_COEFFICIENTS = [
        'emotion_score'      => ['kp' => 0.5,  'ki' => 0.15, 'kd' => 0.25],
        'cool_point_density' => ['kp' => 0.4,  'ki' => 0.10, 'kd' => 0.20],
        'word_count_accuracy'=> ['kp' => 0.3,  'ki' => 0.08, 'kd' => 0.15],
        'quality_score'      => ['kp' => 0.45, 'ki' => 0.12, 'kd' => 0.22],
    ];

    /** @var array{error_integral: float, last_error: float, last_value: float, sample_count: int} */
    private array $state = [];

    /** @var string 当前整定的变量名 */
    private string $currentVar = '';

    /**
     * 构造函数
     */
    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 从数据库加载 PID 状态（之前累积的误差积分和上次误差）
     *
     * @param string $varName 控制变量名
     */
    public function loadState(string $varName): void
    {
        $this->currentVar = $varName;
        $this->state = [
            'error_integral' => 0.0,
            'last_error'     => 0.0,
            'last_value'     => 0.0,
            'sample_count'   => 0,
        ];

        try {
            $row = DB::fetch(
                'SELECT state_data FROM pid_states WHERE novel_id = ? AND var_name = ?',
                [$this->novelId, $varName]
            );
            if ($row && !empty($row['state_data'])) {
                $data = json_decode($row['state_data'], true);
                if ($data) {
                    $this->state = array_merge($this->state, $data);
                }
            }
        } catch (\Throwable $e) {
            // 表可能不存在，降级使用零值
        }
    }

    /**
     * 将 PID 状态持久化到数据库
     *
     * @param string $varName 控制变量名
     */
    public function saveState(string $varName): void
    {
        try {
            $data = json_encode($this->state, JSON_UNESCAPED_UNICODE);
            $exists = DB::fetch(
                'SELECT id FROM pid_states WHERE novel_id = ? AND var_name = ?',
                [$this->novelId, $varName]
            );
            if ($exists) {
                DB::update('pid_states', [
                    'state_data' => $data,
                ], 'novel_id = ? AND var_name = ?', [$this->novelId, $varName]);
            } else {
                DB::insert('pid_states', [
                    'novel_id'   => $this->novelId,
                    'var_name'   => $varName,
                    'state_data' => $data,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('PIDController::saveState 失败：' . $e->getMessage());
        }
    }

    /**
     * 计算 PID 整定输出
     *
     * @param string $varName   控制变量名
     * @param float  $current   当前实际值
     * @param float  $target    目标值
     * @return array{p_correction: float, i_correction: float, d_correction: float, total: float,
     *               recommendation: string, severity: string, trend: string}
     */
    public function compute(string $varName, float $current, float $target): array
    {
        $coeff = self::PID_COEFFICIENTS[$varName] ?? self::PID_COEFFICIENTS['quality_score'];

        $this->loadState($varName);

        $error = $target - $current;

        // --- P 项：比例控制（当前偏差） ---
        $pCorrection = $coeff['kp'] * $error;

        // --- I 项：积分控制（累积偏差） ---
        // 衰减积分：每次只用最近的积分窗口，防止积分饱和
        $decayFactor = 0.85;
        $this->state['error_integral'] = ($this->state['error_integral'] * $decayFactor) + $error;
        $iCorrection = $coeff['ki'] * $this->state['error_integral'];

        // --- D 项：微分控制（变化率） ---
        // 标准PID：D = Kd * (error - last_error) / Δt，每个采样周期(1章)为Δt=1
        // derivative < 0 表示偏差在缩小（改善），derivative > 0 表示偏差在扩大（恶化）
        $derivative = 0.0;
        if ($this->state['sample_count'] > 0) {
            $derivative = $error - $this->state['last_error'];
        }
        $dCorrection = $coeff['kd'] * $derivative;

        $totalCorrection = $pCorrection + $iCorrection + $dCorrection;

        $trend = 'steady';
        // derivative = error - last_error
        // derivative < 0 → 偏差缩小（改善，因为 error 在减小）
        // derivative > 0 → 偏差扩大（恶化，因为 error 在增大）
        if ($derivative < -0.05) $trend = 'improving';
        elseif ($derivative > 0.05) $trend = 'worsening';

        // --- 严重度 ---
        $severity = 'normal';
        $absError = abs($error) / max(1, $target);
        $absIntegral = abs($this->state['error_integral']);
        if ($absError > 0.3 || $absIntegral > 3.0) {
            $severity = 'critical';
        } elseif ($absError > 0.15 || $absIntegral > 1.5) {
            $severity = 'warning';
        }

        // --- 生成自然语言建议 ---
        $recommendation = $this->buildRecommendation(
            $varName, $current, $target,
            $pCorrection, $iCorrection, $dCorrection,
            $trend, $severity
        );

        // --- 更新内部状态 ---
        $this->state['last_error']   = $error;
        $this->state['last_value']   = $current;
        $this->state['sample_count'] = $this->state['sample_count'] + 1;

        if ($this->state['sample_count'] > 100) {
            $this->state['error_integral'] *= 0.5;
            $this->state['sample_count'] = 50;
        }

        return [
            'p_correction'   => round($pCorrection, 3),
            'i_correction'   => round($iCorrection, 3),
            'd_correction'   => round($dCorrection, 3),
            'total'          => round($totalCorrection, 3),
            'recommendation' => $recommendation,
            'severity'       => $severity,
            'trend'          => $trend,
            'error'          => round($error, 3),
            'integral'       => round($this->state['error_integral'], 3),
            'derivative'     => round($derivative, 4),
        ];
    }

    /**
     * 根据 PID 三项贡献构建自然语言建议
     */
    private function buildRecommendation(
        string $varName, float $current, float $target,
        float $p, float $i, float $d,
        string $trend, string $severity
    ): string {
        $labels = [
            'emotion_score'       => '情绪密度',
            'cool_point_density'  => '爽点密度',
            'word_count_accuracy' => '字数控制',
            'quality_score'       => '章节质量',
        ];
        $label = $labels[$varName] ?? $varName;

        $currentDisplay = $varName === 'cool_point_density'
            ? round($current * 100, 1) . '%章有爽点'
            : round($current, 1) . '分';

        $targetDisplay = $varName === 'cool_point_density'
            ? round($target * 100, 1) . '%章有爽点'
            : round($target, 1) . '分';

        $parts = [];

        if ($severity === 'critical') {
            $parts[] = "【PID急调】{$label}当前{$currentDisplay}，偏离目标{$targetDisplay}严重";
        } elseif ($severity === 'warning') {
            $parts[] = "【PID预警】{$label}当前{$currentDisplay}，目标{$targetDisplay}";
        } else {
            $parts[] = "【PID微调】{$label}：{$currentDisplay} → {$targetDisplay}";
        }

        if (abs($p) >= 2) {
            $dir = $p > 0 ? '提升' : '降低';
            $parts[] = "P项建议{$dir}" . round(abs($p), 1) . "分（当前偏差）";
        }

        if (abs($i) >= 1.5) {
            $parts[] = "I项累积偏差" . round($this->state['error_integral'], 1) . "，建议加大力度";
        }

        if (abs($d) >= 1 && $trend === 'worsening') {
            $parts[] = "D项检测恶化趋势，立即干预防止滑落";
        } elseif ($trend === 'improving' && $severity === 'critical') {
            $parts[] = "D项显示正在改善中，保持当前方向";
        }

        return implode('。', $parts);
    }

    /**
     * 批量评估多个控制变量
     *
     * @param array{emotion_score: float|null, cool_point_density: float|null,
     *             word_count_accuracy: float|null, quality_score: float|null} $currents
     * @return array
     */
    public function evaluateAll(array $currents): array
    {
        $targets = [
            'emotion_score'       => 75.0,
            'cool_point_density'  => 0.29,
            'word_count_accuracy' => 0.85,
            'quality_score'       => 80.0,
        ];

        $results = [];
        foreach ($targets as $var => $target) {
            $current = $currents[$var] ?? null;
            if ($current === null) continue;
            $result = $this->compute($var, (float)$current, $target);
            $this->saveState($var);
            $results[$var] = $result;
        }

        return $results;
    }

    /**
     * 重置 PID 状态（新卷开始或主动重置时）
     */
    public function reset(string $varName): void
    {
        try {
            DB::delete('pid_states', 'novel_id = ? AND var_name = ?', [$this->novelId, $varName]);
        } catch (\Throwable $e) {
            error_log('PIDController::reset 失败：' . $e->getMessage());
        }
        $this->state = [
            'error_integral' => 0.0,
            'last_error'     => 0.0,
            'last_value'     => 0.0,
            'sample_count'   => 0,
        ];
    }

    /**
     * 重置所有控制变量
     */
    public function resetAll(): void
    {
        foreach (array_keys(self::PID_COEFFICIENTS) as $var) {
            $this->reset($var);
        }
    }
}
