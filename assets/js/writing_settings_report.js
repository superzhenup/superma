// 写作设置页（writing_settings.php）：作者画像详细报告。由内联脚本原样外置。
function loadDetailedReport(profileId) {
    var section = document.getElementById('detailedReportSection');
    if (section.style.display !== 'none' && section.innerHTML) {
        section.style.display = 'none';
        return;
    }

    if (section.innerHTML) {
        section.style.display = '';
        return;
    }

    section.style.display = '';
    section.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>正在加载详细报告...</div>';

    fetch(apiRouteUrl('author_profile', 'action=detailed-report&id=' + encodeURIComponent(profileId)))
        .then(function(res) {
            if (!res.ok) {
                return res.text().then(function(t) {
                    var msg = '加载失败';
                    try { msg = JSON.parse(t).error || msg; } catch(e) { msg = t.substring(0, 200); }
                    throw new Error(msg);
                });
            }
            return res.json();
        })
        .then(function(report) {
            if (!report.success) {
                throw new Error(report.error || '加载失败');
            }
            renderDetailedReport(section, report);
        })
        .catch(function(err) {
            section.textContent = '';
            var alertBox = document.createElement('div');
            alertBox.className = 'alert alert-danger';
            alertBox.textContent = '加载详细报告失败: ' + err.message;
            section.appendChild(alertBox);
        });
}

/**
 * 详细报告包含上传作品标题与 AI 分析结果，二者都属于不可信输入。
 * 保留报告所需的受控 HTML 结构，但必须经过 app.js 的统一白名单净化；
 * 安全组件未加载时宁可拒绝渲染，也不能退化为原始 innerHTML。
 */
function setDetailedReportHtml(container, html) {
    if (typeof safeHtml !== 'function') {
        container.textContent = '安全净化组件未加载，详细报告暂不可显示。请刷新页面后重试。';
        return;
    }
    container.innerHTML = safeHtml(html);
}

function renderDetailedReport(container, report) {
    var dims = report.dimensions || {};
    var chapters = report.chapter_stats || [];
    var dimOrder = ['writing_habits', 'narrative_style', 'sentiment', 'creative_identity'];
    var dimIcons = { writing_habits: 'bi-pen', narrative_style: 'bi-eye', sentiment: 'bi-heart', creative_identity: 'bi-stars' };
    var dimColors = { writing_habits: 'primary', narrative_style: 'success', sentiment: 'danger', creative_identity: 'warning' };

    var html = '';
    html += '<div class="d-flex justify-content-between align-items-center mb-3">';
    html += '<h6><i class="bi bi-file-earmark-bar-graph me-2"></i>详细分析报告</h6>';
    html += '<small class="text-secondary">总' + (report.total_chapters || 0) + '章 · ' + ((report.total_chars || 0) / 10000).toFixed(1) + '万字</small>';
    html += '</div>';

    html += '<ul class="nav nav-pills mb-3" id="reportTabs" role="tablist">';
    html += '<li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">概览</button></li>';
    for (var i = 0; i < dimOrder.length; i++) {
        var dim = dimOrder[i];
        html += '<li class="nav-item"><button class="nav-link ' + (dim in dims ? '' : 'disabled') + '" data-bs-toggle="tab" data-bs-target="#tab-' + dim + '" type="button">' + dimensionLabels[dim] + '</button></li>';
    }
    html += '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-chapters" type="button">章节特征</button></li>';
    html += '</ul>';

    html += '<div class="tab-content">';

    // Overview tab
    html += '<div class="tab-pane fade show active" id="tab-overview">';
    html += renderOverviewTab(dims);
    html += '</div>';

    // Dimension tabs
    for (var i = 0; i < dimOrder.length; i++) {
        var dim = dimOrder[i];
        html += '<div class="tab-pane fade" id="tab-' + dim + '">';
        if (dim in dims) {
            html += renderDimensionDetail(dim, dims[dim], dimensionLabels[dim], dimIcons[dim], dimColors[dim]);
        } else {
            html += '<p class="text-secondary">该维度暂无分析数据</p>';
        }
        html += '</div>';
    }

    // Chapter tab
    html += '<div class="tab-pane fade" id="tab-chapters">';
    html += renderChapterStats(chapters);
    html += '</div>';

    html += '</div>';

    setDetailedReportHtml(container, html);
}

function renderOverviewTab(dims) {
    var items = [
        { key: 'writing_habits', label: '写作习惯', icon: 'bi-pen', color: 'primary', extract: null },
        { key: 'narrative_style', label: '叙事手法', icon: 'bi-eye', color: 'success', extract: null },
        { key: 'sentiment', label: '思想情感', icon: 'bi-heart', color: 'danger', extract: null },
        { key: 'creative_identity', label: '创作个性', icon: 'bi-stars', color: 'warning', extract: null },
    ];

    var html = '<div class="row g-3">';
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        var dim = dims[item.key];
        html += '<div class="col-md-6"><div class="card border-' + item.color + ' h-100">';
        html += '<div class="card-header bg-' + item.color + ' bg-opacity-10 py-2">';
        html += '<i class="bi ' + item.icon + ' me-1"></i><strong>' + item.label + '</strong>';
        if (dim && dim.confidence !== undefined) {
            html += '<span class="badge bg-' + item.color + ' float-end">置信度 ' + Math.round(dim.confidence * 100) + '%</span>';
        }
        html += '</div>';
        html += '<div class="card-body py-2 small">';
        if (dim) {
            html += renderDimensionSummary(item.key, dim);
        } else {
            html += '<span class="text-secondary">暂无数据</span>';
        }
        html += '</div></div></div>';
    }
    html += '</div>';
    return html;
}

function renderDimensionSummary(dim, data) {
    var lines = [];
    switch (dim) {
        case 'writing_habits':
            if (data.sentence_length_avg) lines.push('平均句长：' + data.sentence_length_avg + '字');
            if (data.paragraph_length_avg) lines.push('平均段长：' + data.paragraph_length_avg + '字');
            if (data.word_complexity) lines.push('词汇复杂度：' + data.word_complexity);
            if (data.uniqueness_score) lines.push('独特性评分：' + Math.round(data.uniqueness_score * 100) + '%');
            if (data.use_dialogue > 0) lines.push('对话密度：' + (data.use_dialogue * 100).toFixed(2) + '%');
            if (data.metaphor_frequency) lines.push('比喻频率：' + data.metaphor_frequency);
            var rd = data.rhetorical_devices;
            if (rd && Object.keys(rd).length > 0) {
                var rdList = [];
                for (var k in rd) { rdList.push((LABELS.rhetoric[k] || k) + '(' + rd[k] + ')'); }
                lines.push('修辞：' + rdList.join(', '));
            }
            break;

        case 'narrative_style':
            if (data.narrative_pov) lines.push('视角：' + (LABELS.pov[data.narrative_pov] || data.narrative_pov));
            if (data.pacing_type) lines.push('节奏：' + data.pacing_type);
            if (data.chapter_structure) lines.push('结构：' + data.chapter_structure);
            if (data.cliffhanger_usage > 0) lines.push('悬念钩子：' + Math.round(data.cliffhanger_usage * 100) + '%');
            if (data.description_density) lines.push('描写密度：' + data.description_density);
            if (data.tension_curve && data.tension_curve.pattern) lines.push('张力模式：' + data.tension_curve.pattern);
            break;

        case 'sentiment':
            if (data.overall_tone) lines.push('整体基调：' + (LABELS.tone[data.overall_tone] || data.overall_tone));
            if (data.emotion_intensity) lines.push('情感强度：' + data.emotion_intensity);
            if (data.depth_level) lines.push('思想深度：' + data.depth_level);
            var themes = data.themes;
            if (themes && themes.length > 0) lines.push('主题：' + themes.slice(0, 4).join('、'));
            if (data.violence_level) lines.push('暴力程度：' + data.violence_level);
            if (data.moral_framework) lines.push('道德框架：' + data.moral_framework);
            break;

        case 'creative_identity':
            if (data.writing_voice) lines.push('文风：' + data.writing_voice);
            var gp = data.genre_preferences;
            if (gp && gp.length > 0) lines.push('题材偏好：' + gp.join('、'));
            var st = data.style_tags;
            if (st && st.length > 0) lines.push('风格标签：' + st.join('、'));
            var pp = data.plot_preferences;
            if (pp && pp.length > 0) lines.push('情节偏好：' + pp.join('、'));
            if (data.editing_style) lines.push('编辑风格：' + data.editing_style);
            if (data.planning_style) lines.push('创作规划：' + data.planning_style);
            break;
    }
    return lines.length > 0 ? lines.join('<br>') : '<span class="text-secondary">数据不足</span>';
}

function renderDimensionDetail(dim, data, name, icon, color) {
    var html = '';

    // Confidence bar
    if (data.confidence !== undefined) {
        html += '<div class="mb-3">';
        html += '<small class="text-secondary">分析置信度</small>';
        html += '<div class="progress" style="height:6px;"><div class="progress-bar bg-' + color + '" style="width:' + Math.round(data.confidence * 100) + '%"></div></div>';
        html += '<small class="text-secondary float-end">' + Math.round(data.confidence * 100) + '%</small>';
        html += '</div>';
    }

    // Writing Habits detail
    if (dim === 'writing_habits') {
        html += '<div class="row g-3">';

        if (data.sentence_length_avg || data.paragraph_length_avg) {
            html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">句子与段落</small>';
            html += '<div class="d-flex justify-content-between mt-1">';
            html += '<span>平均句长</span><strong>' + (data.sentence_length_avg || '-') + '字</strong>';
            html += '</div>';
            html += '<div class="d-flex justify-content-between">';
            html += '<span>平均段长</span><strong>' + (data.paragraph_length_avg || '-') + '字</strong>';
            html += '</div>';
            if (data.sentence_patterns) {
                var sp = data.sentence_patterns;
                html += '<div class="mt-2">';
                html += '<small class="text-secondary">句式占比</small>';
                html += renderMiniBar('短句', sp.short_ratio || 0, 'info');
                html += renderMiniBar('长句', sp.long_ratio || 0, 'warning');
                html += renderMiniBar('陈述', sp.declarative_ratio || 0, 'primary');
                html += renderMiniBar('感叹', sp.exclamatory_ratio || 0, 'danger');
                html += renderMiniBar('疑问', sp.interrogative_ratio || 0, 'success');
                html += '</div>';
            }
            html += '</div></div></div>';
        }

        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">用词特征</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>词汇复杂度</span><strong>' + (data.word_complexity || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>独特性评分</span><strong>' + (data.uniqueness_score ? Math.round(data.uniqueness_score * 100) + '%' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>对话密度</span><strong>' + (data.use_dialogue > 0 ? (data.use_dialogue * 100).toFixed(2) + '%' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>被动语态</span><strong>' + (data.use_passive > 0 ? data.use_passive.toFixed(2) + '‰' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>比喻频率</span><strong>' + (data.metaphor_frequency || '-') + '</strong></div>';
        html += '</div></div></div>';

        var vp = data.vocabulary_preference;
        if (vp && Object.keys(vp).length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">高频词汇 Top ' + Math.min(15, Object.keys(vp).length) + '</small>';
            html += '<div class="mt-1">';
            var vpEntries = Object.entries(vp).sort(function(a, b) { return b[1] - a[1]; }).slice(0, 15);
            var maxVp = vpEntries.length > 0 ? vpEntries[0][1] : 1;
            for (var vi = 0; vi < vpEntries.length; vi++) {
                var pct = Math.round(vpEntries[vi][1] / maxVp * 100);
                html += '<span class="badge bg-secondary me-1 mb-1" style="opacity:' + (0.4 + pct / 250) + '">' + vpEntries[vi][0] + ' ×' + vpEntries[vi][1] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var rd = data.rhetorical_devices;
        if (rd && Object.keys(rd).length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">修辞手法分布</small>';
            html += '<div class="row mt-1">';
            var rdMax = Math.max.apply(null, Object.values(rd));
            for (var rk in rd) {
                var rpct = rdMax > 0 ? Math.round(rd[rk] / rdMax * 100) : 0;
                html += '<div class="col-4"><div class="d-flex justify-content-between small"><span>' + (LABELS.rhetoric[rk] || rk) + '</span><span>' + rd[rk] + '</span></div>';
                html += '<div class="progress mb-1" style="height:4px"><div class="progress-bar bg-info" style="width:' + rpct + '%"></div></div></div>';
            }
            html += '</div></div></div></div>';
        }
        html += '</div>';
    }

    // Narrative Style detail
    if (dim === 'narrative_style') {
        html += '<div class="row g-3">';
        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">叙事基础</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>叙事视角</span><strong>' + (data.narrative_pov || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>节奏类型</span><strong>' + (data.pacing_type || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>章节结构</span><strong>' + (data.chapter_structure || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>视角切换</span><strong>' + (data.pov_switch_frequency || '-') + '</strong></div>';
        html += '</div></div></div>';

        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">叙事技法</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>悬念钩子率</span><strong>' + (data.cliffhanger_usage ? Math.round(data.cliffhanger_usage * 100) + '%' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>内心独白密度</span><strong>' + (data.interior_monologue ? data.interior_monologue.toFixed(2) + '‰' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>描写密度</span><strong>' + (data.description_density || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>场景过渡</span><strong>' + (data.scene_transition_style || '-') + '</strong></div>';
        html += '</div></div></div>';

        var tc = data.tension_curve;
        if (tc && tc.distribution) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">张力曲线 · 模式：' + (tc.pattern || '-') + '</small>';
            html += '<div class="mt-2">';
            var tensionOrder = ['peak', 'rising', 'neutral', 'falling', 'low'];
            var tcMax = Math.max.apply(null, Object.values(tc.distribution));
            for (var ti = 0; ti < tensionOrder.length; ti++) {
                var tk = tensionOrder[ti];
                var tv = tc.distribution[tk] || 0;
                var tpct = tcMax > 0 ? Math.round(tv / tcMax * 100) : 0;
                html += '<div class="d-flex align-items-center mb-1">';
                html += '<span class="small me-2" style="width:40px">' + (LABELS.tension[tk] || tk) + '</span>';
                html += '<div class="flex-grow-1"><div class="progress" style="height:6px"><div class="progress-bar bg-' + (LABELS.tensionColors[tk] || 'secondary') + '" style="width:' + tpct + '%"></div></div></div>';
                html += '<span class="small ms-2" style="width:30px">' + tv + '</span>';
                html += '</div>';
            }
            html += '</div></div></div></div>';
        }
        html += '</div>';
    }

    // Sentiment detail
    if (dim === 'sentiment') {
        html += '<div class="row g-3">';
        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">情感基调</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>整体基调</span><strong>' + (data.overall_tone || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>情感强度</span><strong>' + (data.emotion_intensity || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>思想深度</span><strong>' + (data.depth_level || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>主题复杂度</span><strong>' + (data.thematic_complexity ? Math.round(data.thematic_complexity * 100) + '%' : '-') + '</strong></div>';
        html += '</div></div></div>';

        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">价值与风格</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>道德框架</span><strong>' + (data.moral_framework || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>暴力程度</span><strong>' + (data.violence_level || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>审美风格</span><strong>' + (data.aesthetic_style || '-') + '</strong></div>';
        html += '</div></div></div>';

        var er = data.emotional_range;
        if (er && Object.keys(er).length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">情感分布</small>';
            html += '<div class="mt-2">';
            var erSorted = Object.entries(er).sort(function(a, b) { return b[1] - a[1]; });
            var erMax = erSorted.length > 0 ? erSorted[0][1] : 1;
            for (var ei = 0; ei < erSorted.length; ei++) {
                var epct = Math.round(erSorted[ei][1] / erMax * 100);
                html += '<div class="d-flex align-items-center mb-1">';
                html += '<span class="small me-2" style="width:50px">' + (LABELS.emotion[erSorted[ei][0]] || erSorted[ei][0]) + '</span>';
                html += '<div class="flex-grow-1"><div class="progress" style="height:6px"><div class="progress-bar bg-' + (LABELS.emotionColors[erSorted[ei][0]] || 'secondary') + '" style="width:' + epct + '%"></div></div></div>';
                html += '<span class="small ms-2" style="width:40px">' + erSorted[ei][1].toFixed(1) + '</span>';
                html += '</div>';
            }
            html += '</div></div></div></div>';
        }

        var bdf = data.beauty_description_focus;
        if (bdf && bdf.length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">描写侧重点</small>';
            html += '<div class="mt-1">';
            for (var bi = 0; bi < bdf.length; bi++) {
                html += '<span class="badge bg-info me-1 mb-1">' + (LABELS.beautyFocus[bdf[bi]] || bdf[bi]) + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var vt = data.values_tendency;
        if (vt && vt.length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">价值倾向（按权重排序）</small>';
            html += '<div class="mt-1">';
            for (var vi2 = 0; vi2 < vt.length; vi2++) {
                html += '<span class="badge bg-success me-1 mb-1">' + (LABELS.valueTendency[vt[vi2]] || vt[vi2]) + '</span>';
            }
            html += '</div></div></div></div>';
        }
        html += '</div>';
    }

    // Creative Identity detail
    if (dim === 'creative_identity') {
        html += '<div class="row g-3">';

        html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">文风语音</small>';
        html += '<p class="mt-1 mb-0">' + (data.writing_voice || '未分析') + '</p>';
        html += '</div></div></div>';

        var gp = data.genre_preferences;
        if (gp && gp.length > 0) {
            html += '<div class="col-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">题材偏好</small>';
            html += '<div class="mt-1">';
            for (var gi = 0; gi < gp.length; gi++) {
                html += '<span class="badge bg-primary me-1 mb-1">' + gp[gi] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var st = data.style_tags;
        if (st && st.length > 0) {
            html += '<div class="col-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">风格标签</small>';
            html += '<div class="mt-1">';
            for (var si = 0; si < st.length; si++) {
                html += '<span class="style-tag me-1 mb-1">' + st[si] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var pp = data.plot_preferences;
        if (pp && pp.length > 0) {
            html += '<div class="col-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">情节偏好</small>';
            html += '<div class="mt-1">';
            for (var pi = 0; pi < pp.length; pi++) {
                html += '<span class="badge bg-warning text-dark me-1 mb-1">' + pp[pi] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var ca = data.character_archetype_favorites;
        if (ca && ca.length > 0) {
            html += '<div class="col-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">角色原型偏好</small>';
            html += '<div class="mt-1">';
            for (var ci = 0; ci < ca.length; ci++) {
                html += '<span class="badge bg-success me-1 mb-1">' + (LABELS.characterArchetype[ca[ci]] || ca[ci]) + '</span>';
            }
            html += '</div></div></div></div>';
        }

        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">创作流程</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>编辑风格</span><strong>' + (data.editing_style || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>创作规划</span><strong>' + (data.planning_style || '-') + '</strong></div>';
        html += '</div></div></div>';

        var ut = data.unique_techniques;
        if (ut && ut.length > 0) {
            html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">独特技法</small>';
            html += '<div class="mt-1">';
            for (var ui = 0; ui < ut.length; ui++) {
                html += '<span class="badge bg-info me-1 mb-1">' + ut[ui] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var tm = data.trademark_elements;
        if (tm && tm.length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">标志性元素</small>';
            html += '<div class="mt-1">';
            for (var tmi = 0; tmi < tm.length; tmi++) {
                html += '<span class="style-tag me-1 mb-1">' + tm[tmi] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var sp = data.signature_phrases;
        if (sp && sp.length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">标志句式</small>';
            html += '<div class="mt-1 text-muted small">';
            for (var spi = 0; spi < Math.min(sp.length, 10); spi++) {
                html += '<code class="me-2 mb-1 d-inline-block">' + sp[spi] + '</code>';
            }
            html += '</div></div></div></div>';
        }

        html += '</div>';
    }

    return html;
}

function renderMiniBar(label, ratio, color) {
    var pct = Math.round(ratio * 100);
    return '<div class="d-flex align-items-center mb-1 small">' +
        '<span style="width:36px">' + label + '</span>' +
        '<div class="flex-grow-1 mx-1"><div class="progress" style="height:4px"><div class="progress-bar bg-' + color + '" style="width:' + pct + '%"></div></div></div>' +
        '<span style="width:30px;text-align:right">' + pct + '%</span>' +
        '</div>';
}

function renderChapterStats(chapters) {
    if (!chapters || chapters.length === 0) {
        return '<p class="text-secondary">暂无章节数据</p>';
    }

    var charCounts = chapters.map(function(c) { return c.char_count; });
    var totalChars = charCounts.reduce(function(a, b) { return a + b; }, 0);
    var avgChars = Math.round(totalChars / chapters.length);
    var maxChars = Math.max.apply(null, charCounts);
    var minChars = Math.min.apply(null, charCounts);

    var html = '';

    html += '<div class="row g-3 mb-3">';
    html += '<div class="col-md-3"><div class="card bg-body-tertiary text-center py-2"><small class="text-secondary">总章节</small><h5 class="mb-0">' + chapters.length + '</h5></div></div>';
    html += '<div class="col-md-3"><div class="card bg-body-tertiary text-center py-2"><small class="text-secondary">总字数</small><h5 class="mb-0">' + (totalChars / 10000).toFixed(2) + '万</h5></div></div>';
    html += '<div class="col-md-3"><div class="card bg-body-tertiary text-center py-2"><small class="text-secondary">平均字数/章</small><h5 class="mb-0">' + avgChars + '</h5></div></div>';
    html += '<div class="col-md-3"><div class="card bg-body-tertiary text-center py-2"><small class="text-secondary">字数区间</small><h5 class="mb-0">' + minChars + '~' + maxChars + '</h5></div></div>';
    html += '</div>';

    html += '<div class="mb-3"><small class="text-secondary">各章字数分布</small></div>';
    html += '<div class="mb-3" style="max-height:200px;overflow-y:auto">';
    for (var i = 0; i < chapters.length; i++) {
        var ch = chapters[i];
        var barPct = maxChars > 0 ? Math.round(ch.char_count / maxChars * 100) : 0;
        var barColor = ch.char_count > avgChars * 1.5 ? 'warning' : ch.char_count < avgChars * 0.5 ? 'danger' : 'primary';
        html += '<div class="d-flex align-items-center mb-1 small">';
        html += '<span style="width:60px;text-align:right" class="me-2 text-secondary">第' + ch.number + '章</span>';
        html += '<div class="flex-grow-1"><div class="progress" style="height:8px"><div class="progress-bar bg-' + barColor + '" style="width:' + barPct + '%"></div></div></div>';
        html += '<span class="ms-2" style="width:60px">' + ch.char_count + '字</span>';
        html += '<span class="ms-1 text-secondary" style="width:50px">' + (ch.title || '') + '</span>';
        html += '</div>';
    }
    html += '</div>';

    var hasDialogue = chapters.some(function(c) { return c.dialogue_density > 0; });
    if (hasDialogue) {
        html += '<div class="mb-3"><small class="text-secondary mt-2">各章对话密度对比</small></div>';
        html += '<div class="mb-3" style="max-height:200px;overflow-y:auto">';
        var maxDd = Math.max.apply(null, chapters.map(function(c) { return c.dialogue_density; }));
        for (var j = 0; j < chapters.length; j++) {
            var ch2 = chapters[j];
            var ddPct = maxDd > 0 ? Math.round(ch2.dialogue_density / maxDd * 100) : 0;
            html += '<div class="d-flex align-items-center mb-1 small">';
            html += '<span style="width:60px;text-align:right" class="me-2 text-secondary">第' + ch2.number + '章</span>';
            html += '<div class="flex-grow-1"><div class="progress" style="height:6px"><div class="progress-bar bg-success" style="width:' + ddPct + '%"></div></div></div>';
            html += '<span class="ms-2" style="width:60px">' + (ch2.dialogue_count || 0) + '处</span>';
            html += '</div>';
        }
        html += '</div>';
    }

    return html;
}
