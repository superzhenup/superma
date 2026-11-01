<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 写作参数 API
 * 
 * 提供写作参数的异步读取和保存接口
 * 被 writing_settings.php 的 params 标签页使用
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/daemon/DaemonSchedule.php';
require_once __DIR__ . '/../includes/harness/HarnessEmergencyStop.php';
requireLoginApi();

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'get';

// 加载参数定义
$paramDefsFile = __DIR__ . '/../config/writing_params.php';
$paramDefs = file_exists($paramDefsFile) ? require $paramDefsFile : [];

// 从集中配置同步默认值
$wsDefaults = getWritingDefaults();
foreach ($wsDefaults as $key => $def) {
    if (isset($paramDefs[$key])) {
        $paramDefs[$key]['default'] = $def['default'];
    }
}

try {
    switch ($action) {
        case 'bump_harness_emergency_epoch':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('必须使用 POST 方法');
            }
            csrf_verify();

            try {
                $epoch = HarnessEmergencyStop::bump();
            } catch (Throwable $bumpError) {
                error_log(sprintf(
                    '[HARNESS_EMERGENCY_STOP] user=%d bump_failed=%s',
                    currentUserId(),
                    $bumpError->getMessage()
                ));
                throw $bumpError;
            }
            error_log(sprintf(
                '[HARNESS_EMERGENCY_STOP] user=%d epoch=%d->%d',
                currentUserId(),
                $epoch['previous_epoch'],
                $epoch['current_epoch']
            ));
            echo json_encode([
                'success' => true,
                'message' => '紧急停止代际已递增；在途写作将在下一安全检查点停止',
                'data' => $epoch,
            ]);
            break;

        case 'get':
            $currentValues = [];
            if (!empty($paramDefs)) {
                $keys = array_keys($paramDefs);
                $placeholders = implode(',', array_fill(0, count($keys), '?'));
                $rows = DB::fetchAll(
                    "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)",
                    $keys
                );
                foreach ($rows as $r) {
                    $currentValues[$r['setting_key']] = $r['setting_value'];
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'defs' => $paramDefs,
                    'values' => $currentValues,
                ],
            ]);
            break;

        case 'save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('必须使用 POST 方法');
            }

            csrf_verify();

            $input = $_POST;
            $savedCount = 0;
            $errors = [];

            foreach ($paramDefs as $key => $def) {
                // Monotonic kill-switch state is never a generic form value.
                // Silently skip it because the settings form submits every
                // visible field; only the authenticated bump action may write.
                if (HarnessEmergencyStop::isProtectedSetting((string)$key)) {
                    continue;
                }
                $rawValue = $input[$key] ?? null;
                if ($rawValue === null) {
                    continue;
                }

                try {
                    if ($def['type'] === 'number') {
                        if (!is_numeric($rawValue)) {
                            $errors[$key] = "{$def['label']} 必须是数字";
                            continue;
                        }
                        $val = max($def['min'], min($def['max'], (int)$rawValue));
                    } elseif ($def['type'] === 'range') {
                        if (!is_numeric($rawValue)) {
                            $errors[$key] = "{$def['label']} 必须是数字";
                            continue;
                        }
                        $val = max($def['min'], min($def['max'], (float)$rawValue));
                    } elseif ($def['type'] === 'select') {
                        $val = isset($def['options'][$rawValue]) ? $rawValue : $def['default'];
                    } elseif ($def['type'] === 'time') {
                        $val = trim((string)$rawValue);
                        if (!DaemonSchedule::isValidTime($val)) {
                            $errors[$key] = "{$def['label']} 必须是 HH:MM 格式的有效时间";
                            continue;
                        }
                    } else {
                        $val = trim((string)$rawValue);
                    }

                    $existing = DB::fetch(
                        'SELECT setting_key FROM system_settings WHERE setting_key = ?',
                        [$key]
                    );

                    if ($existing) {
                        DB::update('system_settings',
                            ['setting_value' => (string)$val],
                            'setting_key=?', [$key]
                        );
                    } else {
                        DB::insert('system_settings', [
                            'setting_key'   => $key,
                            'setting_value' => (string)$val,
                        ]);
                    }
                    $savedCount++;
                } catch (\Throwable $e) {
                    error_log("writing_params save {$key} 失败: " . $e->getMessage());
                    $errors[$key] = "{$def['label']} 保存失败，请稍后重试";
                }
            }
            clearSystemSettingsCache();

            echo json_encode([
                'success' => $errors === [],
                'message' => $errors === []
                    ? "已保存 {$savedCount} 项写作参数"
                    : '部分参数格式无效，请修正后重试',
                'saved_count' => $savedCount,
                'errors' => $errors,
            ]);
            break;

        case 'reset':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('必须使用 POST 方法');
            }

            csrf_verify();

            $resetCount = 0;
            foreach ($paramDefs as $key => $def) {
                // Resetting to zero would create a 0 -> 1 -> 0 ABA and could
                // re-authorize an old worker. Epoch is increment-only forever.
                if (HarnessEmergencyStop::isProtectedSetting((string)$key)) {
                    continue;
                }
                $val = (string)$def['default'];

                try {
                    $existing = DB::fetch(
                        'SELECT setting_key FROM system_settings WHERE setting_key = ?',
                        [$key]
                    );

                    if ($existing) {
                        DB::update('system_settings',
                            ['setting_value' => $val],
                            'setting_key=?', [$key]
                        );
                    } else {
                        DB::insert('system_settings', [
                            'setting_key'   => $key,
                            'setting_value' => $val,
                        ]);
                    }
                    $resetCount++;
                } catch (\Throwable $e) {
                    error_log("重置参数 {$key} 失败: " . $e->getMessage());
                }
            }
            clearSystemSettingsCache();

            echo json_encode([
                'success' => true,
                'message' => '可重置的写作参数已恢复默认值；紧急停止代际保持不变',
                'reset_count' => $resetCount,
            ]);
            break;

        default:
            throw new Exception('未知的操作：' . $action);
    }
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => '操作失败，请稍后重试',
        'message' => '操作失败，请稍后重试',
        'code'    => 'internal_error',
        'request_id' => error_trace_id(),
    ]);
}
