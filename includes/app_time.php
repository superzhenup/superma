<?php
defined('APP_LOADED') or die('Direct access denied.');

// 用户界面与每日运行时段默认使用北京时间；数据库、租约仍使用 UTC。
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Shanghai');
}

function appTimezone(): DateTimeZone
{
    static $timezone = null;
    return $timezone ??= new DateTimeZone(APP_TIMEZONE);
}

/** 将数据库 UTC 时间转换为应用显示时间，不改写存储值。 */
function formatAppTime(?string $value, string $format = 'Y-m-d H:i:s'): string
{
    $value = trim($value ?? '');
    if ($value === '' || str_starts_with($value, '0000-')
        || !preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $value)) {
        return '';
    }

    try {
        // 字符串自带偏移时 DateTimeImmutable 会尊重它，避免重复加八小时。
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return '';
        }
        return $date->setTimezone(appTimezone())->format($format);
    } catch (Exception $e) {
        return '';
    }
}
