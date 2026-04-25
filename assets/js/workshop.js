/**
 * 创意工坊前端交互
 */

// 当前生成的结果
let currentResult = null;

// 剧情模式详情
const plotDetails = {
    'linear_growth': {
        title: '线性成长型（经典爽文模式）',
        content: '<strong>核心：</strong>主角从弱小起步，通过努力、奇遇或金手指，一步步克服困难，实力/地位不断提升。<br><strong>特点：</strong>目标明确（变强、复仇、守护），节奏清晰，爽点密集。<br><strong>适用：</strong>玄幻、仙侠、都市异能、系统文等。'
    },
    'unit_puzzle': {
        title: '单元解谜/副本探索型',
        content: '<strong>核心：</strong>主角进入一个个相对独立的"副本"或"案件"，解决谜题、战胜敌人，并逐步揭开背后的主线真相。<br><strong>特点：</strong>节奏快，悬念强，每个单元有完整起承转合。<br><strong>适用：</strong>规则怪谈、惊悚直播、推理侦探、无限流。'
    },
    'apocalypse': {
        title: '救世/末世生存型',
        content: '<strong>核心：</strong>世界面临崩溃或已被摧毁，主角在绝望中寻找希望，对抗终极威胁（天灾、邪神、外星文明）。<br><strong>特点：</strong>压抑与燃点并存，强调人性的挣扎与牺牲。<br><strong>适用：</strong>末世废土、克苏鲁、科幻史诗。'
    },
    'intellectual_battle': {
        title: '智斗/布局型',
        content: '<strong>核心：</strong>多方势力（包括主角、反派、第三方）通过信息差、策略、阴谋进行较量，剧情充满反转。<br><strong>特点：</strong>逻辑严密，伏笔深远，读者需要动脑参与。<br><strong>适用：</strong>权谋历史、都市商战、悬疑推理。'
    },
    'anti_cliche': {
        title: '反套路/解构型',
        content: '<strong>核心：</strong>颠覆传统网文套路（如废柴逆袭、龙傲天），用幽默或荒诞的方式解构经典设定。<br><strong>特点：</strong>轻松搞笑，脑洞清奇，常有"第四面墙"互动。<br><strong>适用：</strong>搞笑吐槽、系统bug流、穿书反派文。'
    },
    'custom': {
        title: '自定义剧情模式',
        content: '请在下方输入您的自定义剧情模式说明。'
    }
};

// 大结局风格详情
const endingDetails = {
    'happy_ending': {
        title: '圆满胜利型（大团圆）',
        content: '<strong>特点：</strong>主角达成所有目标（击败最终BOSS、拯救世界、抱得美人归），一切问题得到解决，世界恢复和平。<br><strong>读者感受：</strong>满足、治愈、热血沸腾。'
    },
    'open_ending': {
        title: '开放式结局（留白）',
        content: '<strong>特点：</strong>主线矛盾解决，但留下一些次要线索或未解之谜，让读者自行想象。<br><strong>读者感受：</strong>回味无穷，引发讨论。'
    },
    'tragic_hero': {
        title: '悲剧/牺牲型',
        content: '<strong>特点：</strong>主角成功拯救世界或达成目标，但付出了巨大代价（如失去挚爱、自身消亡、世界满目疮痍）。<br><strong>读者感受：</strong>震撼、意难平、深刻。'
    },
    'dark_twist': {
        title: '黑暗反转型',
        content: '<strong>特点：</strong>结局揭露一个颠覆性的真相（如"拯救的世界是虚拟的"、"主角才是最终BOSS"、"一切都是轮回"），让之前的剧情有了全新解读。<br><strong>读者感受：</strong>震惊、烧脑、需要二刷。'
    },
    'daily_return': {
        title: '日常回归型',
        content: '<strong>特点：</strong>经历波澜壮阔的冒险后，主角回归平静生活，强调"平凡可贵"。<br><strong>读者感受：</strong>温馨、治愈、尘埃落定。'
    },
    'sequel_setup': {
        title: '续作铺垫型',
        content: '<strong>特点：</strong>当前危机解除，但引出更大的世界观或威胁，为续集埋下伏笔。<br><strong>读者感受：</strong>期待、好奇，但也可能因"烂尾感"被吐槽。'
    },
    'custom': {
        title: '自定义大结局风格',
        content: '请在下方输入您的自定义大结局风格说明。'
    }
};

/**
 * 页面加载完成后初始化
 */
document.addEventListener('DOMContentLoaded', function() {
    // 绑定类型选择事件
    document.getElementById('genre-select').addEventListener('change', function() {
        toggleCustomInput('genre');
    });
    
    document.getElementById('style-select').addEventListener('change', function() {
        toggleCustomInput('style');
    });
    
    // 绑定剧情模式选择事件
    document.getElementById('plot-pattern').addEventListener('change', function() {
        updatePlotDetail(this.value);
    });
    
    // 绑定大结局风格选择事件
    document.getElementById('ending-style').addEventListener('change', function() {
        updateEndingDetail(this.value);
    });
    
    // 检查模型配置
    checkModelConfig();
});

/**
 * 检查模型配置
 */
async function checkModelConfig() {
    const modelSelect = document.getElementById('model-select');
    const modelId = modelSelect ? modelSelect.value : null;
    
    try {
        const url = modelId 
            ? `api/workshop.php?action=check_model&model_id=${modelId}`
            : 'api/workshop.php?action=check_model';
        
        const response = await fetch(url);
        const result = await response.json();
        
        if (!result.success) {
            console.warn('模型配置检查:', result.error);
        }
    } catch (error) {
        console.warn('检查模型配置失败:', error);
    }
}

/**
 * 切换自定义输入框显示
 */
function toggleCustomInput(type) {
    const select = document.getElementById(type + '-select');
    const customInput = document.getElementById(type + '-custom');
    
    if (select.value === '__custom__') {
        customInput.style.display = 'block';
        customInput.focus();
    } else {
        customInput.style.display = 'none';
        customInput.value = '';
    }
}

/**
 * 更新剧情模式详情
 */
function updatePlotDetail(value) {
    const detailSection = document.getElementById('plot-detail');
    const customSection = document.getElementById('plot-custom-section');
    const detailContent = document.getElementById('plot-detail-content');
    
    if (value && plotDetails[value]) {
        detailSection.style.display = 'block';
        detailContent.innerHTML = '<strong>' + plotDetails[value].title + '</strong><br>' + plotDetails[value].content;
        
        // 显示/隐藏自定义输入框
        customSection.style.display = value === 'custom' ? 'block' : 'none';
    } else {
        detailSection.style.display = 'none';
        customSection.style.display = 'none';
    }
}

/**
 * 更新大结局风格详情
 */
function updateEndingDetail(value) {
    const customSection = document.getElementById('ending-custom-section');
    
    if (value === 'custom') {
        customSection.style.display = 'block';
    } else {
        customSection.style.display = 'none';
    }
}

/**
 * 重置表单
 */
function resetForm() {
    document.getElementById('idea-input').value = '';
    document.getElementById('genre-select').value = '';
    document.getElementById('style-select').value = '';
    document.getElementById('plot-pattern').value = '';
    document.getElementById('ending-style').value = '';
    document.getElementById('extra-settings').value = '';
    document.getElementById('model-select').value = '';
    
    // 隐藏自定义输入框
    document.getElementById('genre-custom').style.display = 'none';
    document.getElementById('style-custom').style.display = 'none';
    document.getElementById('plot-custom-section').style.display = 'none';
    document.getElementById('ending-custom-section').style.display = 'none';
    document.getElementById('plot-detail').style.display = 'none';
    
    // 隐藏结果
    document.getElementById('empty-state').style.display = 'block';
    document.getElementById('loading-state').style.display = 'none';
    document.getElementById('result-content').style.display = 'none';
    document.getElementById('result-actions').style.display = 'none';
    
    currentResult = null;
}

/**
 * 生成创意（流式显示）
 */
async function generateIdea() {
    const idea = document.getElementById('idea-input').value.trim();
    
    if (!idea) {
        alert('请输入小说思路');
        return;
    }
    
    // 获取表单数据
    const genreSelect = document.getElementById('genre-select').value;
    const genreCustom = document.getElementById('genre-custom').value.trim();
    const genre = genreSelect === '__custom__' ? genreCustom : genreSelect;
    
    const styleSelect = document.getElementById('style-select').value;
    const styleCustom = document.getElementById('style-custom').value.trim();
    const style = styleSelect === '__custom__' ? styleCustom : styleSelect;
    
    const plotPattern = document.getElementById('plot-pattern').value;
    const plotCustomDesc = document.getElementById('plot-custom-desc').value.trim();
    const endingStyle = document.getElementById('ending-style').value;
    const endingCustomDesc = document.getElementById('ending-custom-desc').value.trim();
    const extraSettings = document.getElementById('extra-settings').value.trim();
    const modelId = document.getElementById('model-select').value;
    
    // 显示加载状态
    document.getElementById('empty-state').style.display = 'none';
    document.getElementById('loading-state').style.display = 'block';
    document.getElementById('result-content').style.display = 'none';
    document.getElementById('result-actions').style.display = 'none';
    
    // 清除之前的预览
    const oldPreview = document.getElementById('stream-preview');
    if (oldPreview) {
        oldPreview.remove();
    }
    
    // 更新加载状态文字
    updateLoadingStatus('正在准备...');
    
    // 禁用按钮
    const generateBtn = document.getElementById('generate-btn');
    generateBtn.disabled = true;
    generateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>生成中...';
    
    try {
        // 使用流式 API
        await generateWithStream({
            idea,
            genre,
            style,
            plot_pattern: plotPattern,
            plot_custom_desc: plotCustomDesc,
            ending_style: endingStyle,
            ending_custom_desc: endingCustomDesc,
            extra_settings: extraSettings,
            model_id: modelId || null
        });
    } catch (error) {
        alert('生成失败: ' + error.message);
        document.getElementById('empty-state').style.display = 'block';
        document.getElementById('loading-state').style.display = 'none';
    } finally {
        generateBtn.disabled = false;
        generateBtn.innerHTML = '<i class="bi bi-magic me-1"></i>一键生成';
    }
}

/**
 * 更新加载状态文字
 */
function updateLoadingStatus(text) {
    const loadingText = document.querySelector('#loading-state p');
    if (loadingText) {
        loadingText.textContent = text;
    }
}

/**
 * 流式生成
 */
async function generateWithStream(params) {
    let fullContent = '';
    let rawResult = '';
    let currentEvent = '';
    let receivedData = false;
    let timeoutId = null;
    
    try {
        const controller = new AbortController();
        
        // 设置 30 秒超时，如果没有任何数据返回则切换到非流式
        timeoutId = setTimeout(() => {
            if (!receivedData) {
                console.warn('流式响应超时，切换到非流式模式');
                controller.abort();
            }
        }, 30000);
        
        const response = await fetch('api/workshop.php?action=generate_stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(params),
            signal: controller.signal
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        
        while (true) {
            const { done, value } = await reader.read();
            
            if (done) break;
            
            buffer += decoder.decode(value, { stream: true });
            
            // 解析 SSE 事件
            const lines = buffer.split('\n');
            buffer = lines.pop() || '';
            
            for (const line of lines) {
                if (line.startsWith('event: ')) {
                    currentEvent = line.substring(7).trim();
                    continue;
                }
                
                if (line.startsWith('data: ')) {
                    const data = line.substring(6);
                    
                    try {
                        const parsed = JSON.parse(data);
                        receivedData = true; // 标记已收到数据
                        
                        // 清除超时计时器
                        if (timeoutId) {
                            clearTimeout(timeoutId);
                            timeoutId = null;
                        }
                        
                        // 根据事件类型处理
                        switch (currentEvent) {
                            case 'model':
                                updateLoadingStatus(`使用模型: ${parsed.name || ''}`);
                                break;
                                
                            case 'status':
                                updateLoadingStatus(parsed.message || parsed || '处理中...');
                                break;
                                
                            case 'debug':
                                // 调试信息，显示在控制台和状态中
                                console.log('[Debug]', parsed.message);
                                updateLoadingStatus(parsed.message || '调试中...');
                                break;
                                
                            case 'chunk':
                                // 流式内容
                                const chunk = typeof parsed === 'string' ? parsed : (parsed.content || '');
                                fullContent += chunk;
                                updateLoadingStatus(`生成中... (${fullContent.length} 字)`);
                                // 实时预览
                                previewContent(fullContent);
                                break;
                                
                            case 'done':
                                currentResult = parsed.data;
                                rawResult = parsed.raw;
                                displayResult(parsed.data);
                                break;
                                
                            case 'error':
                                throw new Error(parsed.message || parsed || '生成失败');
                                
                            default:
                                // 兼容旧格式
                                if (parsed.name && parsed.model_name) {
                                    updateLoadingStatus(`使用模型: ${parsed.name}`);
                                } else if (typeof parsed === 'string') {
                                    fullContent += parsed;
                                    updateLoadingStatus(`生成中... (${fullContent.length} 字)`);
                                    previewContent(fullContent);
                                } else if (parsed.data) {
                                    currentResult = parsed.data;
                                    rawResult = parsed.raw;
                                    displayResult(parsed.data);
                                } else if (parsed.message) {
                                    updateLoadingStatus(parsed.message);
                                }
                        }
                    } catch (e) {
                        // JSON 解析失败，可能是纯文本
                        if (data.includes('error') || data.includes('失败')) {
                            throw new Error(data);
                        }
                        console.warn('SSE 数据解析:', e.message, data);
                    }
                    
                    currentEvent = ''; // 重置事件类型
                }
            }
        }
        
        // 如果流式结束但没有收到 done 事件，检查是否有内容
        if (fullContent && !currentResult) {
            // 尝试解析完整内容
            try {
                const parsed = JSON.parse(fullContent);
                currentResult = parsed;
                displayResult(parsed);
            } catch (e) {
                console.warn('无法解析生成结果');
            }
        }
        
    } catch (error) {
        // 清除超时
        if (timeoutId) {
            clearTimeout(timeoutId);
        }
        
        // 如果是超时或中止，尝试非流式方式
        if (error.name === 'AbortError' || !receivedData) {
            console.warn('流式生成失败或超时，尝试非流式:', error.message);
            await generateWithoutStream(params);
            return;
        }
        
        // 其他错误，尝试非流式方式
        console.warn('流式生成失败，尝试非流式:', error.message);
        await generateWithoutStream(params);
    }
}

/**
 * 预览生成内容
 */
function previewContent(content) {
    // 在加载状态中显示实时内容预览
    const loadingState = document.getElementById('loading-state');
    if (loadingState && content) {
        // 创建或更新预览区域
        let previewArea = document.getElementById('stream-preview');
        if (!previewArea) {
            previewArea = document.createElement('div');
            previewArea.id = 'stream-preview';
            previewArea.style.cssText = `
                margin-top: 1rem;
                padding: 1rem;
                background: var(--card-bg);
                border: 1px solid var(--border-color);
                border-radius: 0.5rem;
                max-height: 300px;
                overflow-y: auto;
                font-size: 0.875rem;
                line-height: 1.6;
                white-space: pre-wrap;
                word-break: break-word;
            `;
            loadingState.querySelector('.text-center').appendChild(previewArea);
        }
        
        // 显示最后 500 字符
        const previewText = content.length > 500 ? '...' + content.slice(-500) : content;
        previewArea.textContent = previewText;
    }
}

/**
 * 非流式生成（备用）
 */
async function generateWithoutStream(params) {
    updateLoadingStatus('正在生成...');
    
    const response = await fetch('api/workshop.php?action=generate', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(params)
    });
    
    const result = await response.json();
    
    if (result.success) {
        currentResult = result.data;
        displayResult(result.data);
    } else {
        throw new Error(result.error || '生成失败');
    }
}

/**
 * 显示生成结果
 */
function displayResult(data) {
    // 清除流式预览
    const previewArea = document.getElementById('stream-preview');
    if (previewArea) {
        previewArea.remove();
    }
    
    document.getElementById('loading-state').style.display = 'none';
    document.getElementById('result-content').style.display = 'block';
    document.getElementById('result-actions').style.display = 'flex';
    
    // 填充结果到编辑框
    document.getElementById('result-title').value = data.title || '';
    document.getElementById('result-protagonist-name').value = data.protagonist_name || '';
    document.getElementById('result-protagonist-info').value = data.protagonist_info || '';
    document.getElementById('result-world').value = data.world_settings || '';
    document.getElementById('result-plot').value = data.plot_settings || '';
    
    // 额外设定
    const extraSection = document.getElementById('result-extra-section');
    if (data.extra_settings) {
        extraSection.style.display = 'block';
        document.getElementById('result-extra').value = data.extra_settings;
    } else {
        extraSection.style.display = 'none';
    }
}

/**
 * 重新生成
 */
function regenerateResult() {
    generateIdea();
}

/**
 * 创建小说
 */
function createNovel() {
    if (!currentResult) {
        alert('请先生成创意');
        return;
    }
    
    // 从编辑框读取当前值
    const editedTitle = document.getElementById('result-title').value.trim();
    const editedProtagonistName = document.getElementById('result-protagonist-name').value.trim();
    
    // 显示确认对话框
    document.getElementById('confirm-title').textContent = editedTitle || currentResult.title;
    document.getElementById('confirm-info').textContent = 
        (currentResult.genre || '') + ' · ' + 
        (editedProtagonistName || currentResult.protagonist_name || '未知主角');
    
    const modal = new bootstrap.Modal(document.getElementById('createNovelModal'));
    modal.show();
}

/**
 * 确认创建小说
 */
async function confirmCreateNovel() {
    if (!currentResult) {
        return;
    }
    
    const createBtn = document.querySelector('#createNovelModal .btn-primary');
    createBtn.disabled = true;
    createBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>创建中...';
    
    try {
        // 从编辑框读取（可能已修改的）值
        const editedTitle = document.getElementById('result-title').value.trim();
        const editedProtagonistName = document.getElementById('result-protagonist-name').value.trim();
        const editedProtagonistInfo = document.getElementById('result-protagonist-info').value.trim();
        const editedWorld = document.getElementById('result-world').value.trim();
        const editedPlot = document.getElementById('result-plot').value.trim();
        const editedExtra = document.getElementById('result-extra').value.trim();
        
        const response = await fetch('api/workshop.php?action=create_novel', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                title: editedTitle || currentResult.title,
                genre: document.getElementById('genre-select').value === '__custom__' 
                    ? document.getElementById('genre-custom').value 
                    : document.getElementById('genre-select').value,
                style: document.getElementById('style-select').value === '__custom__'
                    ? document.getElementById('style-custom').value
                    : document.getElementById('style-select').value,
                protagonist_name: editedProtagonistName || currentResult.protagonist_name,
                protagonist_info: editedProtagonistInfo || currentResult.protagonist_info,
                world_settings: editedWorld || currentResult.world_settings,
                plot_settings: editedPlot || currentResult.plot_settings,
                extra_settings: editedExtra || currentResult.extra_settings,
                target_chapters: document.getElementById('target-chapters').value,
                chapter_words: document.getElementById('chapter-words').value,
                cover_color: document.getElementById('cover-color').value,
                model_id: document.getElementById('model-select').value || null
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // 跳转到小说详情页
            window.location.href = 'novel.php?id=' + result.novel_id + '&created=1';
        } else {
            throw new Error(result.error || '创建失败');
        }
    } catch (error) {
        alert('创建失败: ' + error.message);
        createBtn.disabled = false;
        createBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>确认创建';
    }
}

// ================================================================
// 单字段重新生成 / 意见改写
// ================================================================

// 字段中文名映射
const sectionLabels = {
    'protagonist_info': '主角信息',
    'world_settings': '世界观设定',
    'plot_settings': '情节设定',
    'extra_settings': '额外设定'
};

// 字段对应的编辑框 ID 映射
const sectionFieldIds = {
    'protagonist_info': 'result-protagonist-info',
    'world_settings': 'result-world',
    'plot_settings': 'result-plot',
    'extra_settings': 'result-extra'
};

// 当前正在改写的字段
let rewritingSection = null;

/**
 * 获取当前所有字段的上下文
 */
function getCurrentContext() {
    return {
        title: document.getElementById('result-title').value.trim(),
        protagonist_name: document.getElementById('result-protagonist-name').value.trim(),
        protagonist_info: document.getElementById('result-protagonist-info').value.trim(),
        world_settings: document.getElementById('result-world').value.trim(),
        plot_settings: document.getElementById('result-plot').value.trim(),
        extra_settings: document.getElementById('result-extra').value.trim()
    };
}

/**
 * 重新生成单个字段
 */
async function regenerateSection(section) {
    const fieldId = sectionFieldIds[section];
    if (!fieldId) return;
    
    const field = document.getElementById(fieldId);
    const sectionEl = field.closest('.result-section');
    
    // 添加加载状态
    sectionEl.classList.add('rewriting');
    
    // 在编辑框后插入加载提示
    let spinner = sectionEl.querySelector('.rewrite-spinner');
    if (!spinner) {
        spinner = document.createElement('div');
        spinner.className = 'rewrite-spinner';
        spinner.innerHTML = '<span class="spinner-border spinner-border-sm"></span> AI 正在重新生成...';
        field.parentNode.insertBefore(spinner, field.nextSibling);
    }
    spinner.style.display = 'flex';
    
    try {
        const modelId = document.getElementById('model-select').value || null;
        const context = getCurrentContext();
        
        const response = await fetch('api/workshop.php?action=rewrite_section', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                section: section,
                context: context,
                feedback: '',
                model_id: modelId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            field.value = result.content;
            // 更新 currentResult
            if (currentResult) {
                currentResult[section] = result.content;
            }
        } else {
            throw new Error(result.error || '重新生成失败');
        }
    } catch (error) {
        alert('重新生成失败: ' + error.message);
    } finally {
        sectionEl.classList.remove('rewriting');
        spinner.style.display = 'none';
    }
}

/**
 * 打开意见改写模态框
 */
function openRewriteModal(section) {
    rewritingSection = section;
    document.getElementById('rewrite-section-label').textContent = sectionLabels[section] || section;
    document.getElementById('rewrite-feedback').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('rewriteModal'));
    modal.show();
}

/**
 * 确认改写
 */
async function confirmRewrite() {
    if (!rewritingSection) return;
    
    const feedback = document.getElementById('rewrite-feedback').value.trim();
    if (!feedback) {
        alert('请输入修改意见');
        return;
    }
    
    const confirmBtn = document.getElementById('rewrite-confirm-btn');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>改写中...';
    
    // 关闭模态框
    const modal = bootstrap.Modal.getInstance(document.getElementById('rewriteModal'));
    if (modal) modal.hide();
    
    const fieldId = sectionFieldIds[rewritingSection];
    const field = document.getElementById(fieldId);
    const sectionEl = field.closest('.result-section');
    
    // 添加加载状态
    sectionEl.classList.add('rewriting');
    let spinner = sectionEl.querySelector('.rewrite-spinner');
    if (!spinner) {
        spinner = document.createElement('div');
        spinner.className = 'rewrite-spinner';
        spinner.innerHTML = '<span class="spinner-border spinner-border-sm"></span> AI 正在根据意见改写...';
        field.parentNode.insertBefore(spinner, field.nextSibling);
    }
    spinner.style.display = 'flex';
    
    try {
        const modelId = document.getElementById('model-select').value || null;
        const context = getCurrentContext();
        
        const response = await fetch('api/workshop.php?action=rewrite_section', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                section: rewritingSection,
                context: context,
                feedback: feedback,
                model_id: modelId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            field.value = result.content;
            // 更新 currentResult
            if (currentResult) {
                currentResult[rewritingSection] = result.content;
            }
        } else {
            throw new Error(result.error || '改写失败');
        }
    } catch (error) {
        alert('改写失败: ' + error.message);
    } finally {
        sectionEl.classList.remove('rewriting');
        spinner.style.display = 'none';
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="bi bi-magic me-1"></i>开始改写';
        rewritingSection = null;
    }
}

// ================================================================
// 将函数显式暴露到 window，供 HTML 内联 onclick 调用
// （修复点击"重新生成/意见改写"按钮无反应的问题）
// ================================================================
window.resetForm             = resetForm;
window.generateIdea          = generateIdea;
window.regenerateResult      = regenerateResult;
window.createNovel           = createNovel;
window.confirmCreateNovel    = confirmCreateNovel;
window.regenerateSection     = regenerateSection;
window.openRewriteModal      = openRewriteModal;
window.confirmRewrite        = confirmRewrite;
