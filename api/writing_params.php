<?php
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
                    $errors[$key] = "{$def['label']} 保存失败: " . $e->getMessage();
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "已保存 {$savedCount} 项写作参数",
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

            echo json_encode([
                'success' => true,
                'message' => '所有写作参数已重置为系统默认值',
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
        'error' => $e->getMessage(),
        'message' => $e->getMessage(),
    ]);
}
