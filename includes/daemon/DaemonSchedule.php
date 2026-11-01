<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/app_time.php';

/**
 * 挂机写作每日运行窗口。
 *
 * 只阻止“新章节”在窗口外启动；已经开始的章节允许完整收尾，避免中途取消造成
 * 正文丢失或重复计费。开始时间等于结束时间时按全天运行处理。
 */
final class DaemonSchedule
{
    public const DEFAULT_ENABLED = false;
    public const DEFAULT_START = '00:00';
    public const DEFAULT_END = '06:00';

    /**
     * @return array{enabled:bool,allowed:bool,all_day:bool,start:string,end:string,cross_midnight:bool,window_label:string,timezone:string,now:string,next_start_at:?string,message:string}
     */
    public static function fromSettings(?DateTimeImmutable $now = null): array
    {
        return self::evaluate(
            (bool)getSystemSetting('ws_daemon_schedule_enabled', self::DEFAULT_ENABLED, 'bool'),
            (string)getSystemSetting('ws_daemon_window_start', self::DEFAULT_START),
            (string)getSystemSetting('ws_daemon_window_end', self::DEFAULT_END),
            $now
        );
    }

    /**
     * @return array{enabled:bool,allowed:bool,all_day:bool,start:string,end:string,cross_midnight:bool,window_label:string,timezone:string,now:string,next_start_at:?string,message:string}
     */
    public static function evaluate(
        bool $enabled,
        string $start,
        string $end,
        ?DateTimeImmutable $now = null
    ): array {
        $start = self::normalizeTime($start, self::DEFAULT_START);
        $end = self::normalizeTime($end, self::DEFAULT_END);
        $timezone = appTimezone();
        $now = ($now ?: new DateTimeImmutable('now', $timezone))->setTimezone($timezone);

        $startMinutes = self::toMinutes($start);
        $endMinutes = self::toMinutes($end);
        $nowMinutes = ((int)$now->format('G') * 60) + (int)$now->format('i');
        $allDay = $startMinutes === $endMinutes;
        $crossMidnight = $startMinutes > $endMinutes;

        $inside = $allDay
            || (!$crossMidnight && $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes)
            || ($crossMidnight && ($nowMinutes >= $startMinutes || $nowMinutes < $endMinutes));
        $allowed = !$enabled || $inside;

        $nextStartAt = null;
        if ($enabled && !$allowed) {
            $candidate = $now->setTime(intdiv($startMinutes, 60), $startMinutes % 60, 0);
            if ($candidate <= $now) $candidate = $candidate->modify('+1 day');
            $nextStartAt = $candidate->format('Y-m-d H:i');
        }

        $windowLabel = $allDay
            ? '全天运行'
            : $start . '–' . $end . ($crossMidnight ? '（跨日）' : '');
        $message = !$enabled
            ? '未启用运行时间限制'
            : ($allowed ? '当前处于挂机运行时段' : '当前处于休眠时段，下一次启动：' . $nextStartAt);

        return [
            'enabled' => $enabled,
            'allowed' => $allowed,
            'all_day' => $allDay,
            'start' => $start,
            'end' => $end,
            'cross_midnight' => $crossMidnight,
            'window_label' => $windowLabel,
            'timezone' => $timezone->getName(),
            'now' => $now->format('Y-m-d H:i'),
            'next_start_at' => $nextStartAt,
            'message' => $message,
        ];
    }

    public static function isValidTime(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim($value)) === 1;
    }

    private static function normalizeTime(string $value, string $fallback): string
    {
        $value = trim($value);
        return self::isValidTime($value) ? $value : $fallback;
    }

    private static function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time, 2));
        return ($hours * 60) + $minutes;
    }
}
