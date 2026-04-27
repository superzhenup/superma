<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

// 获取用户的小说列表
$novels = DB::fetchAll(
    'SELECT id, title, status, target_chapters FROM novels WHERE user_id=? ORDER BY id DESC',
    [$_SESSION['user_id']]
);

pageHeader('系统功能诊断', 'diagnostic');
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-card">
                <div class="page-card-header">
                    <i class="bi bi-activity me-2"></i>系统功能诊断工具
                </div>
                <div class="p-3">
                    <p class="text-muted mb-3">
                        全面验证小说写作系统的核心功能是否正常生效，包括记忆引擎、伏笔系统、质量检测、写作引擎、进度感知等模块。
                    </p>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">选择小说</label>
                            <select class="form-select" id="novel-select">
                                <option value="">-- 请选择小说 --</option>
                                <?php foreach ($novels as $n): ?>
                                <option value="<?= $n['id'] ?>">
                                    <?= h($n['title']) ?> 
                                    (<?= $n['status'] === 'completed' ? '已完成' : '写作中' ?>)
                                    <?php if ($n['target_chapters']): ?>
                                    - 目标 <?= $n['target_chapters'] ?> 章
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">测试类型</label>
                            <select class="form-select" id="test-type">
                                <option value="all">全部测试</option>
                                <option value="database">数据库检查</option>
                                <option value="memory">记忆引擎</option>
                                <option value="foreshadowing">伏笔系统</option>
                                <option value="quality">质量检测</option>
                                <option value="writing">写作引擎</option>
                                <option value="progress">进度感知</option>
                                <option value="settings">系统设置</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-primary w-100" id="run-diagnostic">
                                <i class="bi bi-play-fill me-1"></i>运行诊断
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 诊断结果区域 -->
    <div id="result-area" style="display:none;">
        <!-- 总体健康度 -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="page-card">
                    <div class="p-4 text-center">
                        <div id="health-score-display">
                            <div class="health-score-circle">
                                <span id="health-score">--</span>
                            </div>
                            <div id="health-status" class="mt-2 fs-5">等待诊断...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 统计摘要 -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="page-card text-center p-3">
                    <div class="fs-3 text-success fw-bold" id="stat-passed">0</div>
                    <div class="text-muted small">通过</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="page-card text-center p-3">
                    <div class="fs-3 text-warning fw-bold" id="stat-warnings">0</div>
                    <div class="text-muted small">警告</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="page-card text-center p-3">
                    <div class="fs-3 text-danger fw-bold" id="stat-failed">0</div>
                    <div class="text-muted small">失败</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="page-card text-center p-3">
                    <div class="fs-3 text-secondary fw-bold" id="stat-total">0</div>
                    <div class="text-muted small">总测试</div>
                </div>
            </div>
        </div>
        
        <!-- 详细测试结果 -->
        <div class="row">
            <div class="col-12">
                <div class="page-card">
                    <div class="page-card-header">
                        <i class="bi bi-list-check me-2"></i>详细测试结果
                    </div>
                    <div class="p-3" id="test-results">
                        <!-- 动态填充 -->
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 加载动画 -->
    <div id="loading-overlay" style="display:none;">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="mt-3 text-muted">正在执行诊断测试...</div>
        </div>
    </div>
</div>

<style>
.health-score-circle {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    border: 8px solid #dee2e6;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 3rem;
    font-weight: bold;
    transition: all 0.3s ease;
}

.health-score-circle.healthy {
    border-color: #28a745;
    color: #28a745;
}

.health-score-circle.warning {
    border-color: #ffc107;
    color: #ffc107;
}

.health-score-circle.critical {
    border-color: #dc3545;
    color: #dc3545;
}

.test-category {
    margin-bottom: 1.5rem;
}

.test-category-header {
    font-weight: 600;
    color: #fff;
    padding: 0.5rem 1rem;
    border-radius: 0.25rem;
    margin-bottom: 0.5rem;
}

.test-item {
    padding: 0.75rem 1rem;
    border-left: 3px solid #dee2e6;
    margin-bottom: 0.5rem;
    background: rgba(255,255,255,0.05);
    border-radius: 0 0.25rem 0.25rem 0;
}

.test-item.pass {
    border-left-color: #28a745;
}

.test-item.warning {
    border-left-color: #ffc107;
}

.test-item.fail, .test-item.error {
    border-left-color: #dc3545;
}

.test-item.critical {
    border-left-color: #dc3545;
    background: rgba(220, 53, 69, 0.1);
}

.test-status-badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
}

.test-details {
    margin-top: 0.5rem;
    padding: 0.5rem;
    background: rgba(0,0,0,0.2);
    border-radius: 0.25rem;
    font-size: 0.85rem;
}
</style>

<script>
$(document).ready(function() {
    $('#run-diagnostic').click(function() {
        const novelId = $('#novel-select').val();
        const testType = $('#test-type').val();
        
        if (!novelId) {
            alert('请选择一部小说');
            return;
        }
        
        // 显示加载动画
        $('#loading-overlay').show();
        $('#result-area').hide();
        
        // 调用诊断 API
        $.ajax({
            url: 'api/system_diagnostic.php',
            method: 'GET',
            data: {
                novel_id: novelId,
                test_type: testType
            },
            dataType: 'json',
            success: function(data) {
                $('#loading-overlay').hide();
                $('#result-area').show();
                
                if (data.error) {
                    alert('诊断失败：' + data.error);
                    return;
                }
                
                renderResults(data);
            },
            error: function(xhr, status, error) {
                $('#loading-overlay').hide();
                alert('请求失败：' + error);
            }
        });
    });
    
    function renderResults(data) {
        // 更新健康度
        const score = data.health_score || 0;
        const status = data.health_status || 'unknown';
        
        $('#health-score').text(score + '%');
        $('#health-score-circle').removeClass('healthy warning critical').addClass(status);
        
        const statusText = {
            'healthy': '✅ 系统健康',
            'warning': '⚠️ 需要关注',
            'critical': '❌ 存在问题'
        };
        $('#health-status').text(statusText[status] || '未知状态');
        
        // 更新统计
        $('#stat-passed').text(data.summary.passed || 0);
        $('#stat-warnings').text(data.summary.warnings || 0);
        $('#stat-failed').text(data.summary.failed || 0);
        $('#stat-total').text(data.summary.total_tests || 0);
        
        // 渲染详细结果
        const $results = $('#test-results');
        $results.empty();
        
        const categoryColors = {
            'database': '#6c757d',
            'memory': '#0dcaf0',
            'foreshadowing': '#fd7e14',
            'quality': '#20c997',
            'writing': '#6610f2',
            'progress': '#d63384',
            'settings': '#6f42c1'
        };
        
        const categoryNames = {
            'database': '数据库检查',
            'memory': '记忆引擎',
            'foreshadowing': '伏笔系统',
            'quality': '质量检测',
            'writing': '写作引擎',
            'progress': '进度感知',
            'settings': '系统设置'
        };
        
        for (const [category, tests] of Object.entries(data.tests || {})) {
            const color = categoryColors[category] || '#6c757d';
            const name = categoryNames[category] || category;
            
            let html = `<div class="test-category">
                <div class="test-category-header" style="background: ${color};">
                    <i class="bi bi-folder me-2"></i>${name}
                </div>`;
            
            for (const [testName, result] of Object.entries(tests)) {
                const statusIcon = {
                    'pass': '✅',
                    'warning': '⚠️',
                    'fail': '❌',
                    'critical': '🚨',
                    'error': '💥'
                }[result.status] || '❓';
                
                const statusBadgeClass = {
                    'pass': 'bg-success',
                    'warning': 'bg-warning text-dark',
                    'fail': 'bg-danger',
                    'critical': 'bg-danger',
                    'error': 'bg-secondary'
                }[result.status] || 'bg-secondary';
                
                html += `<div class="test-item ${result.status}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="me-2">${statusIcon}</span>
                            <strong>${testName}</strong>
                        </div>
                        <div>
                            <span class="test-status-badge ${statusBadgeClass}">${result.status.toUpperCase()}</span>
                            ${result.duration ? `<span class="text-muted ms-2 small">${result.duration}ms</span>` : ''}
                        </div>
                    </div>
                    <div class="text-muted small mt-1">${result.message || ''}</div>`;
                
                if (result.details && Object.keys(result.details).length > 0) {
                    html += `<div class="test-details">
                        <pre class="mb-0" style="white-space: pre-wrap; word-break: break-all;">${JSON.stringify(result.details, null, 2)}</pre>
                    </div>`;
                }
                
                html += `</div>`;
            }
            
            html += `</div>`;
            $results.append(html);
        }
    }
});
</script>

<?php pageFooter(); ?>
