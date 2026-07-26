<?php
/**
 * 创意工坊 - AI 小说创意生成器
 * 输入：小说思路、类型、风格、剧情走向、大结局风格
 * 输出：书名、主角姓名、主角信息、世界观设定、情节设定
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$models = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');

pageHeader('创意工坊', 'workshop');
?>

<!-- 创意工坊主界面 -->
<div class="workshop-container">
    <!-- 左侧：输入区 -->
    <div class="workshop-input">
        <div class="page-card">
            <div class="page-card-header">
                <i class="bi bi-lightbulb me-2"></i>创意工坊
                <small class="text-muted ms-2">输入灵感，AI 为您生成完整小说框架</small>
            </div>
            
            <div class="p-4">
                <!-- 核心创意输入 -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-stars me-2"></i>核心创意
                    </div>
                    <div class="mb-3">
                        <label class="form-label">小说思路 <span class="text-danger">*</span></label>
                        <textarea id="idea-input" class="form-control" rows="4" 
                            placeholder="描述您的小说创意，例如：&#10;- 一个普通高中生意外获得修仙传承，在现代都市中隐藏身份修炼...&#10;- 末世降临，主角重生回到灾难发生前三个月，开始疯狂囤积物资...&#10;- 主角穿越到修仙世界，发现自己竟然是反派大BOSS的私生子..."></textarea>
                        <div class="form-text">详细描述您的创意，AI 将据此生成完整的小说框架</div>
                    </div>
                </div>

                <!-- 类型与风格 -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-palette me-2"></i>类型与风格
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">小说类型</label>
                            <select id="genre-select" class="form-select">
                                <option value="">选择类型</option>
                                <option value="玄幻">玄幻</option>
                                <option value="仙侠">仙侠</option>
                                <option value="都市">都市</option>
                                <option value="历史">历史</option>
                                <option value="科幻">科幻</option>
                                <option value="游戏">游戏</option>
                                <option value="悬疑">悬疑</option>
                                <option value="奇幻">奇幻</option>
                                <option value="武侠">武侠</option>
                                <option value="末世">末世</option>
                                <option value="无限流">无限流</option>
                                <option value="规则怪谈">规则怪谈</option>
                                <option value="__custom__">自定义...</option>
                            </select>
                            <input type="text" id="genre-custom" class="form-control mt-2" 
                                   placeholder="输入自定义类型" style="display:none">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">写作风格</label>
                            <select id="style-select" class="form-select">
                                <option value="">选择风格</option>
                                <option value="热血爽文">热血爽文</option>
                                <option value="轻松搞笑">轻松搞笑</option>
                                <option value="暗黑压抑">暗黑压抑</option>
                                <option value="温馨治愈">温馨治愈</option>
                                <option value="悬疑烧脑">悬疑烧脑</option>
                                <option value="史诗宏大">史诗宏大</option>
                                <option value="细腻情感">细腻情感</option>
                                <option value="快节奏">快节奏</option>
                                <option value="慢热种田">慢热种田</option>
                                <option value="__custom__">自定义...</option>
                            </select>
                            <input type="text" id="style-custom" class="form-control mt-2" 
                                   placeholder="输入自定义风格" style="display:none">
                        </div>
                    </div>
                </div>

                <!-- 剧情走向 -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-signpost-2 me-2"></i>剧情走向
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">剧情模式</label>
                            <select id="plot-pattern" class="form-select">
                                <option value="">选择剧情走向模式</option>
                                <option value="linear_growth">1. 线性成长型（经典爽文）</option>
                                <option value="unit_puzzle">2. 单元解谜/副本探索型</option>
                                <option value="apocalypse">3. 救世/末世生存型</option>
                                <option value="intellectual_battle">4. 智斗/布局型</option>
                                <option value="anti_cliche">5. 反套路/解构型</option>
                                <option value="custom">6. 自定义...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">大结局风格</label>
                            <select id="ending-style" class="form-select">
                                <option value="">选择大结局风格</option>
                                <option value="happy_ending">1. 圆满胜利型（大团圆）</option>
                                <option value="open_ending">2. 开放式结局（留白）</option>
                                <option value="tragic_hero">3. 悲剧/牺牲型</option>
                                <option value="dark_twist">4. 黑暗反转型</option>
                                <option value="daily_return">5. 日常回归型</option>
                                <option value="sequel_setup">6. 续作铺垫型</option>
                                <option value="custom">7. 自定义...</option>
                            </select>
                        </div>
                        <div class="col-12" id="plot-custom-section" style="display:none">
                            <label class="form-label">自定义剧情模式说明</label>
                            <textarea id="plot-custom-desc" class="form-control" rows="2"
                                placeholder="描述您想要的剧情走向模式..."></textarea>
                        </div>
                        <div class="col-12" id="ending-custom-section" style="display:none">
                            <label class="form-label">自定义大结局说明</label>
                            <textarea id="ending-custom-desc" class="form-control" rows="2"
                                placeholder="描述您想要的大结局风格..."></textarea>
                        </div>
                    </div>
                    
                    <!-- 剧情模式详情展示 -->
                    <div id="plot-detail" class="mt-3" style="display:none">
                        <div class="alert alert-info mb-0">
                            <div id="plot-detail-content"></div>
                        </div>
                    </div>
                </div>

                <!-- 额外设定 -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-gear me-2"></i>额外设定 <small class="text-muted">（可选）</small>
                    </div>
                    <textarea id="extra-settings" class="form-control" rows="2"
                        placeholder="其他补充要求，例如：&#10;- 主角要有金手指系统&#10;- 要有青梅竹马的女主&#10;- 世界观要融合东方神话"></textarea>
                </div>

                <!-- AI 模型选择 -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-cpu me-2"></i>AI 模型
                    </div>
                    <select id="model-select" class="form-select">
                        <option value="">使用默认模型</option>
                        <?php foreach ($models as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $m['is_default'] ? 'selected' : '' ?>>
                            <?= h($m['name']) ?> (<?= h($m['model_name']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($models)): ?>
                    <div class="form-text text-warning mt-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <a href="settings.php" class="text-warning">请先添加 AI 模型</a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 生成按钮 -->
                <div class="d-flex gap-2 justify-content-end mt-4">
                    <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>重置
                    </button>
                    <button type="button" class="btn btn-primary" id="generate-btn" onclick="generateIdea()">
                        <i class="bi bi-magic me-1"></i>一键生成
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 右侧：生成结果 -->
    <div class="workshop-output">
        <div class="page-card">
            <div class="page-card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-journal-text me-2"></i>生成结果</span>
                <div class="btn-group btn-group-sm" id="result-actions" style="display:none">
                    <button type="button" class="btn btn-outline-primary" onclick="regenerateResult()">
                        <i class="bi bi-arrow-repeat me-1"></i>重新生成
                    </button>
                    <button type="button" class="btn btn-success" onclick="createNovel()">
                        <i class="bi bi-plus-circle me-1"></i>新建小说
                    </button>
                </div>
            </div>
            
            <div class="p-4" id="result-container">
                <!-- 空状态 -->
                <div id="empty-state" class="text-center py-5">
                    <div class="empty-icon mb-3">
                        <i class="bi bi-lightbulb" style="font-size: 3rem; opacity: 0.3"></i>
                    </div>
                    <h6 class="text-muted">等待生成</h6>
                    <p class="text-muted small">填写左侧创意信息，点击"一键生成"</p>
                </div>
                
                <!-- 加载状态 -->
                <div id="loading-state" style="display:none">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <h6 class="text-muted">AI 正在创作中...</h6>
                        <p class="text-muted small">请稍候，这可能需要几秒钟</p>
                    </div>
                </div>
                
                <!-- 生成结果 -->
                <div id="result-content" style="display:none">
                    <!-- 提示条 -->
                    <div class="alert alert-info py-2 mb-3">
                        <i class="bi bi-pencil-square me-1"></i>以下内容均可编辑修改，修改后点击"新建小说"将使用修改后的内容
                    </div>

                    <!-- 书名 -->
                    <div class="result-section">
                        <div class="result-label">
                            <i class="bi bi-bookmark-star me-1"></i>书名
                        </div>
                        <input type="text" class="form-control" id="result-title" placeholder="书名">
                    </div>
                    
                    <!-- 主角信息 -->
                    <div class="result-section">
                        <div class="result-label">
                            <i class="bi bi-person-badge me-1"></i>主角
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">主角姓名</label>
                            <input type="text" class="form-control" id="result-protagonist-name" placeholder="主角姓名">
                        </div>
                        <div>
                            <div class="d-flex justify-content-between align-items-center">
                                <label class="form-label small text-muted mb-0">主角信息</label>
                                <div class="section-actions">
                                    <button type="button" class="btn btn-outline-secondary btn-xs" onclick="regenerateSection('protagonist_info')" title="重新生成">
                                        <i class="bi bi-arrow-repeat"></i> 重新生成
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-xs" onclick="openRewriteModal('protagonist_info')" title="输入意见改写">
                                        <i class="bi bi-pencil"></i> 意见改写
                                    </button>
                                </div>
                            </div>
                            <textarea class="form-control mt-1" id="result-protagonist-info" rows="3" placeholder="主角详细信息"></textarea>
                        </div>
                    </div>
                    
                    <!-- 世界观设定 -->
                    <div class="result-section">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="result-label mb-0">
                                <i class="bi bi-globe me-1"></i>世界观设定
                            </div>
                            <div class="section-actions">
                                <button type="button" class="btn btn-outline-secondary btn-xs" onclick="regenerateSection('world_settings')" title="重新生成">
                                    <i class="bi bi-arrow-repeat"></i> 重新生成
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-xs" onclick="openRewriteModal('world_settings')" title="输入意见改写">
                                    <i class="bi bi-pencil"></i> 意见改写
                                </button>
                            </div>
                        </div>
                        <textarea class="form-control mt-2" id="result-world" rows="4" placeholder="世界观设定"></textarea>
                    </div>
                    
                    <!-- 情节设定 -->
                    <div class="result-section">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="result-label mb-0">
                                <i class="bi bi-signpost-2 me-1"></i>情节设定
                            </div>
                            <div class="section-actions">
                                <button type="button" class="btn btn-outline-secondary btn-xs" onclick="regenerateSection('plot_settings')" title="重新生成">
                                    <i class="bi bi-arrow-repeat"></i> 重新生成
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-xs" onclick="openRewriteModal('plot_settings')" title="输入意见改写">
                                    <i class="bi bi-pencil"></i> 意见改写
                                </button>
                            </div>
                        </div>
                        <textarea class="form-control mt-2" id="result-plot" rows="5" placeholder="情节设定"></textarea>
                    </div>
                    
                    <!-- 额外设定 -->
                    <div class="result-section" id="result-extra-section" style="display:none">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="result-label mb-0">
                                <i class="bi bi-gear me-1"></i>额外设定
                            </div>
                            <div class="section-actions">
                                <button type="button" class="btn btn-outline-secondary btn-xs" onclick="regenerateSection('extra_settings')" title="重新生成">
                                    <i class="bi bi-arrow-repeat"></i> 重新生成
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-xs" onclick="openRewriteModal('extra_settings')" title="输入意见改写">
                                    <i class="bi bi-pencil"></i> 意见改写
                                </button>
                            </div>
                        </div>
                        <textarea class="form-control mt-2" id="result-extra" rows="3" placeholder="额外设定"></textarea>
                    </div>
                    
                    <!-- 写作参数 -->
                    <div class="result-section">
                        <div class="result-label">
                            <i class="bi bi-sliders me-1"></i>推荐参数
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small">目标章数</label>
                                <input type="number" class="form-control form-control-sm" id="target-chapters" value="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">每章字数</label>
                                <input type="number" class="form-control form-control-sm" id="chapter-words" value="2000">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">封面颜色</label>
                                <input type="color" class="form-control form-control-sm" id="cover-color" value="#6366f1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 新建小说确认模态框 -->
<div class="modal fade" id="createNovelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>新建小说</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>确认使用生成的创意创建新小说？</p>
                <div class="alert alert-info mb-0">
                    <strong id="confirm-title"></strong>
                    <div class="small mt-1" id="confirm-info"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="confirmCreateNovel()">
                    <i class="bi bi-check-circle me-1"></i>确认创建
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 意见改写模态框 -->
<div class="modal fade" id="rewriteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>意见改写</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>请输入您对 <strong id="rewrite-section-label"></strong> 的修改意见：</p>
                <textarea class="form-control" id="rewrite-feedback" rows="4"
                    placeholder="例如：&#10;- 主角性格要更加果断，不要犹豫不决&#10;- 增加一个师徒关系的设定&#10;- 世界观要更加黑暗压抑"></textarea>
                <div class="form-text">AI 将根据您的意见重新改写该部分内容</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="rewrite-confirm-btn" onclick="confirmRewrite()">
                    <i class="bi bi-magic me-1"></i>开始改写
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* 创意工坊布局 */
.workshop-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    min-height: calc(100vh - 120px);
}

@media (max-width: 991.98px) {
    .workshop-container {
        grid-template-columns: 1fr;
    }
}

.workshop-input,
.workshop-output {
    min-height: 600px;
}

.workshop-output .page-card {
    position: sticky;
    top: 1rem;
}

/* 结果展示样式 */
.result-section {
    padding: 1rem;
    margin-bottom: 1rem;
    background: var(--card-bg);
    border-radius: 0.5rem;
    border: 1px solid var(--border-color);
}

.result-label {
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--text-muted);
    font-size: 0.875rem;
}

/* 结果区域编辑框样式 */
.result-section .form-control {
    border-radius: 0.375rem;
}

.result-section .form-control:focus {
    border-color: var(--bs-primary);
    box-shadow: 0 0 0 0.15rem rgba(var(--bs-primary-rgb), 0.15);
}

.result-section textarea {
    resize: vertical;
    min-height: 60px;
}

/* 字段操作按钮 */
.section-actions {
    display: flex;
    gap: 0.375rem;
    flex-shrink: 0;
}

.btn-xs {
    padding: 0.15rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 0.25rem;
    white-space: nowrap;
}

.btn-xs i {
    font-size: 0.7rem;
}

/* 改写加载状态 */
.result-section.rewriting textarea {
    opacity: 0.6;
    pointer-events: none;
}

.result-section .rewrite-spinner {
    display: none;
    font-size: 0.75rem;
    color: var(--bs-primary);
    margin-top: 0.25rem;
}

.result-section.rewriting .rewrite-spinner {
    display: flex;
    align-items: center;
    gap: 0.375rem;
}

/* 空状态 */
.empty-icon {
    color: var(--text-muted);
}

/* 加载动画 */
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.loading-text {
    animation: pulse 1.5s ease-in-out infinite;
}
</style>

<script src="assets/js/workshop.js"></script>

<?php pageFooter(); ?>
