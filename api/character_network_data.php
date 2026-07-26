<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * API: 获取角色关系图数据
 * GET /api/character_network_data.php?novel_id=123
 *
 * 数据来源（优先级从高到低）：
 *   1. novel_characters.relationships JSON 字段
 *   2. character_cards.attributes JSON 中的关系数据
 *   3. 同章共现推断（两个角色在同一章节出场则建立"同场"关系）
 *
 * 返回格式:
 * {
 *   "nodes": [{"id":1, "label":"张三", "group":"protagonist"}],
 *   "edges": [{"from":1, "to":2, "label":"父子", "arrows":"to"}],
 *   "stats": {"character_count": 5, "relationship_count": 8}
 * }
 */

define('APP_LOADED', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLoginApi();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/data.php';

$novelId = intval($_GET['novel_id'] ?? 0);
if ($novelId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'missing novel_id'], JSON_UNESCAPED_UNICODE);
    exit;
}
$userId = (int)($_SESSION['user_id'] ?? 0);
checkNovelOwnership($novelId, $userId);

$novel = getNovel($novelId);
if (!$novel) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'novel not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================================================================
// 1. 合并两个角色表的数据
// ================================================================
$charMap = []; // name => data

// 1a. 从 novel_characters 获取角色（主表）
try {
    $rows = DB::fetchAll(
        'SELECT id, name, role_type, relationships FROM novel_characters WHERE novel_id=? ORDER BY id',
        [$novelId]
    );
    foreach ($rows as $r) {
        $name = trim($r['name']);
        if ($name === '') continue;
        $charMap[$name] = [
            'id'            => (int)$r['id'],
            'name'          => $name,
            'role'          => $r['role_type'] ?? 'minor',
            'alive'         => true,
            'relationships' => $r['relationships'],
            'card_title'    => null,
        ];
    }
} catch (\Throwable $e) {
    // 表不存在，跳过
}

// 1b. 从 character_cards 补充
try {
    $cards = DB::fetchAll(
        'SELECT id, name, title, status, alive, attributes FROM character_cards WHERE novel_id=? ORDER BY id',
        [$novelId]
    );
    foreach ($cards as $c) {
        $name = trim($c['name']);
        if ($name === '') continue;
        if (isset($charMap[$name])) {
            $charMap[$name]['alive'] = (int)$c['alive'] === 1;
            $charMap[$name]['card_attributes'] = $c['attributes'];
            $charMap[$name]['card_title'] = $c['title'];
        } else {
            $charMap[$name] = [
                'id'              => (int)$c['id'] + 100000,
                'name'            => $name,
                'role'            => 'minor',
                'alive'           => (int)$c['alive'] === 1,
                'relationships'   => null,
                'card_attributes' => $c['attributes'],
                'card_title'      => $c['title'],
            ];
        }
    }
} catch (\Throwable $e) {
    // 表不存在，跳过
}

// ================================================================
// 2. 角色名去重合并（AI提取常产生"萧天策"/"天魔皇（萧天策）"等重复）
// ================================================================
$mergeRules = []; // alias => canonical_name (允许多个别名合并到同一个主角色)

// 从 character_cards 的 title 字段推断合并关系
// title 如 "天魔皇" 的角色名是 "萧天策（天魔皇）"，说明真实名是 "萧天策"
foreach ($charMap as $name => $data) {
    $title = $data['card_title'] ?? '';
    if ($title === '' || $title === $name) continue;

    // 模式1: "真实名（别名）" → 括号前是真实名
    if (preg_match('/^(.+?)[（(](.+?)[）)]$/u', $name, $m)) {
        $realName = trim($m[1]);
        // 大小写不敏感查找
        $found = $charMap[$realName] ?? null;
        if (!$found) {
            foreach ($charMap as $ck => $cv) {
                if (mb_strtolower($ck) === mb_strtolower($realName)) {
                    $realName = $ck;
                    $found = $cv;
                    break;
                }
            }
        }
        if ($found) {
            $mergeRules[$name] = $realName;
        }
    }

    // 模式2: 名字含 "/" 分隔，如 "萧天策/天魔皇"
    if (mb_strpos($name, '/') !== false) {
        $parts = array_map('trim', explode('/', $name));
        foreach ($parts as $part) {
            // 大小写不敏感查找
            if (isset($charMap[$part])) {
                $mergeRules[$name] = $part;
                break;
            }
            foreach ($charMap as $ck => $cv) {
                if (mb_strtolower($ck) === mb_strtolower($part)) {
                    $mergeRules[$name] = $ck;
                    break 2;
                }
            }
        }
    }
}

// 执行合并：将别名角色的关系合并到主角色，然后删除别名角色
foreach ($mergeRules as $aliasName => $mainName) {
    if (!isset($charMap[$aliasName]) || !isset($charMap[$mainName])) continue;

    // 将别名角色的 relationships 合并到主角色
    $aliasRels = $charMap[$aliasName]['relationships'] ?? null;
    if (!empty($aliasRels)) {
        $mainRels = $charMap[$mainName]['relationships'];
        $mainRelsDecoded = $mainRels ? json_decode($mainRels, true) : [];
        $aliasRelsDecoded = json_decode($aliasRels, true);
        if (is_array($aliasRelsDecoded) && is_array($mainRelsDecoded)) {
            foreach ($aliasRelsDecoded as $k => $v) {
                if (!isset($mainRelsDecoded[$k])) {
                    $mainRelsDecoded[$k] = $v;
                }
            }
            $charMap[$mainName]['relationships'] = json_encode($mainRelsDecoded, JSON_UNESCAPED_UNICODE);
        }
    }

    // 将别名角色的 card_attributes 中的关系合并到主角色
    $aliasAttrs = $charMap[$aliasName]['card_attributes'] ?? null;
    if (!empty($aliasAttrs)) {
        $mainAttrs = $charMap[$mainName]['card_attributes'] ?? null;
        $mainAttrsDecoded = $mainAttrs ? json_decode($mainAttrs, true) : [];
        $aliasAttrsDecoded = json_decode($aliasAttrs, true);
        if (is_array($aliasAttrsDecoded) && is_array($mainAttrsDecoded)) {
            $aliasRelData = $aliasAttrsDecoded['relationships'] ?? $aliasAttrsDecoded['relations'] ?? null;
            if ($aliasRelData && is_array($aliasRelData)) {
                $mainRelKey = isset($mainAttrsDecoded['relationships']) ? 'relationships' : 'relations';
                $mainRelData = $mainAttrsDecoded[$mainRelKey] ?? [];
                if (is_array($mainRelData)) {
                    foreach ($aliasRelData as $k => $v) {
                        if (!isset($mainRelData[$k])) {
                            $mainRelData[$k] = $v;
                        }
                    }
                    $mainAttrsDecoded[$mainRelKey] = $mainRelData;
                    $charMap[$mainName]['card_attributes'] = json_encode($mainAttrsDecoded, JSON_UNESCAPED_UNICODE);
                }
            }
        }
    }

    // 标记别名，后面构建节点时跳过
    $charMap[$aliasName]['_merged_into'] = $mainName;
}

// ================================================================
// 3. 提取关系数据
// ================================================================
$edgeMap = []; // "fromId-toId" => edge data

// 3a. 从 novel_characters.relationships JSON 提取
foreach ($charMap as $name => $char) {
    if (!empty($char['_merged_into'])) continue;
    if (empty($char['relationships'])) continue;

    $rels = json_decode($char['relationships'], true);
    if (!is_array($rels)) continue;

    foreach ($rels as $key => $val) {
        if (is_string($key) && is_string($val)) {
            $relName = $key;
            $relType = $val;
        } elseif (is_array($val) && isset($val['name'])) {
            $relName = $val['name'];
            $relType = $val['type'] ?? $val['relationship'] ?? '关联';
        } else {
            continue;
        }

        $relName = trim($relName);
        if ($relName === '' || !isset($charMap[$relName])) continue;

        $fromId = $char['id'];
        $toId = $charMap[$relName]['id'];
        $edgeKey = min($fromId, $toId) . '-' . max($fromId, $toId);

        if (!isset($edgeMap[$edgeKey])) {
            $edgeMap[$edgeKey] = ['from' => $fromId, 'to' => $toId, 'label' => $relType];
        }
    }
}

// 3b. 从 character_cards.attributes JSON 提取关系
foreach ($charMap as $name => $char) {
    if (!empty($char['_merged_into'])) continue;
    if (empty($char['card_attributes'])) continue;

    $attrs = json_decode($char['card_attributes'], true);
    if (!is_array($attrs)) continue;

    $relData = $attrs['relationships'] ?? $attrs['relations'] ?? null;
    if (!$relData || !is_array($relData)) continue;

    foreach ($relData as $key => $val) {
        $relName = is_string($key) ? $key : ($val['name'] ?? '');
        $relType = is_string($val) ? $val : ($val['type'] ?? $val['relationship'] ?? '关联');
        $relName = trim($relName);
        if ($relName === '' || !isset($charMap[$relName])) continue;

        $fromId = $char['id'];
        $toId = $charMap[$relName]['id'];
        $edgeKey = min($fromId, $toId) . '-' . max($fromId, $toId);

        if (!isset($edgeMap[$edgeKey])) {
            $edgeMap[$edgeKey] = ['from' => $fromId, 'to' => $toId, 'label' => $relType];
        }
    }
}

// 3c. 同章共现推断（仅在无显式关系时启用）
$cooccurrenceInfo = null;
if (empty($edgeMap)) {
    try {
        $chapterChars = []; // charName => [chapter_numbers]
        // 审计修复 M-NEW-1（2026-06-15）：改用流式逐章处理，避免一次性加载
        // 全部章节正文（LONGTEXT）到内存。500+ 章小说原方案可占用 3-7.5MB。
        $charNames = array_keys(array_filter($charMap, function($c) { return empty($c['_merged_into']); }));
        $pdo = DB::connect();
        $stmt = $pdo->prepare('SELECT chapter_number, content FROM chapters WHERE novel_id=? ORDER BY chapter_number');
        $stmt->execute([$novelId]);
        $totalChapters = 0;
        while ($ch = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $totalChapters++;
            $content = $ch['content'] ?? '';
            foreach ($charNames as $cName) {
                if (mb_strpos($content, $cName) !== false) {
                    $chapterChars[$cName][] = (int)$ch['chapter_number'];
                }
            }
        }
        $stmt->closeCursor();

        if ($totalChapters === 0) {
            $cooccurrenceInfo = '该小说暂无章节内容，无法推断角色关系';
        } else {
            $charsInChapters = count($chapterChars);
            if ($charsInChapters < 2) {
                $cooccurrenceInfo = '章节中出现的角色不足2个，无法推断关系';
            } else {
                // 计算共现阈值：只保留共现3次以上的关系，避免图太乱
                $cooccurrenceMin = max(3, intval($totalChapters * 0.03));

                for ($i = 0; $i < count($charNames); $i++) {
                    for ($j = $i + 1; $j < count($charNames); $j++) {
                        $a = $charNames[$i];
                        $b = $charNames[$j];
                        $chA = $chapterChars[$a] ?? [];
                        $chB = $chapterChars[$b] ?? [];
                        $common = count(array_intersect($chA, $chB));
                        if ($common >= $cooccurrenceMin) {
                            $fromId = $charMap[$a]['id'];
                            $toId = $charMap[$b]['id'];
                            $edgeKey = min($fromId, $toId) . '-' . max($fromId, $toId);
                            $edgeMap[$edgeKey] = ['from' => $fromId, 'to' => $toId, 'label' => '同场×' . $common, 'weight' => $common];
                        }
                    }
                }

                if (empty($edgeMap)) {
                    $cooccurrenceInfo = '角色共现次数均低于阈值（' . $cooccurrenceMin . '次），暂无足够关系数据';
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('character_network_data 共现推断失败: ' . $e->getMessage());
        $cooccurrenceInfo = '共现推断失败，请稍后重试';
    }
}

// ================================================================
// 4. 构建 vis-network 格式
// ================================================================
$nodes = [];
$edges = [];

$groupColors = [
    'protagonist' => ['background' => '#4CAF50', 'border' => '#388E3C'],
    'major'       => ['background' => '#F44336', 'border' => '#D32F2F'],
    'minor'       => ['background' => '#2196F3', 'border' => '#1976D2'],
    'background'  => ['background' => '#9E9E9E', 'border' => '#757575'],
];

foreach ($charMap as $name => $char) {
    // 跳过已合并的别名角色
    if (!empty($char['_merged_into'])) continue;

    $group = $char['role'] ?? 'minor';
    $color = $groupColors[$group] ?? $groupColors['minor'];
    $isAlive = $char['alive'] ?? true;
    $title = $char['card_title'] ?? '';
    if ($title && $title !== $name) {
        $title = $name . '（' . $title . '）';
    } else {
        $title = $name;
    }

    $nodes[] = [
        'id'    => $char['id'],
        'label' => $name,
        'group' => $group,
        'color' => $color,
        'shape' => $isAlive ? 'dot' : 'diamond',
        'size'  => $group === 'protagonist' ? 30 : ($group === 'major' ? 22 : 16),
        'font'  => ['size' => $group === 'protagonist' ? 14 : 12, 'color' => '#ffffff'],
        'title' => $title,
    ];
}

foreach ($edgeMap as $edge) {
    $weight = $edge['weight'] ?? 1;
    // 边宽度和透明度根据共现次数缩放
    $edgeWidth = min(1 + $weight * 0.15, 6);
    $opacity = min(0.3 + $weight * 0.03, 1);
    
    $edges[] = [
        'from'   => $edge['from'],
        'to'     => $edge['to'],
        'label'  => $edge['label'],
        'arrows' => '',
        'color'  => ['color' => 'rgba(150,150,150,' . $opacity . ')', 'highlight' => '#fff'],
        'width'  => $edgeWidth,
        'smooth' => ['enabled' => true, 'type' => 'continuous'],
        'font'   => ['size' => 10, 'color' => '#888'],
    ];
}

// 5. 返回JSON
header('Content-Type: application/json; charset=utf-8');
$result = [
    'nodes' => $nodes,
    'edges' => $edges,
    'stats' => [
        'character_count' => count($nodes),
        'relationship_count' => count($edges),
    ]
];
if ($cooccurrenceInfo !== null) {
    $result['info'] = $cooccurrenceInfo;
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
