// 写作设置页（writing_settings.php）：迭代重写配置。由内联脚本原样外置。
// ========== 迭代重写配置 ==========
(function() {
    var IR_MODES = {
        conservative: {
            label: '保守模式',
            config: {
                rewrite: { enabled: true, threshold: 70, min_gain: 10, iterative_mode: true, use_critic_agent: false },
                iterative_refinement: { max_iterations: 1, min_improvement: 5, target_score: 70, quality_decline_threshold: 5 }
            },
            token: '~1x Token'
        },
        balanced: {
            label: '均衡模式',
            config: {
                rewrite: { enabled: true, threshold: 70, min_gain: 8, iterative_mode: true, use_critic_agent: true },
                iterative_refinement: { max_iterations: 3, min_improvement: 5, target_score: 80, quality_decline_threshold: 3 }
            },
            token: '~3x Token'
        },
        aggressive: {
            label: '深度优化',
            config: {
                rewrite: { enabled: true, threshold: 75, min_gain: 5, iterative_mode: true, use_critic_agent: true },
                iterative_refinement: { max_iterations: 5, min_improvement: 3, target_score: 90, quality_decline_threshold: 2 }
            },
            token: '~5x Token'
        },
        fast: {
            label: '快速提升',
            config: {
                rewrite: { enabled: true, threshold: 65, min_gain: 3, iterative_mode: true, use_critic_agent: true },
                iterative_refinement: { max_iterations: 2, min_improvement: 3, target_score: 75, quality_decline_threshold: 5 }
            },
            token: '~2x Token'
        }
    };

    function cfgToForm(cfg) {
        var rw = cfg.rewrite || {};
        var ir = cfg.iterative_refinement || {};
        document.getElementById('ir-enabled').checked = !!rw.enabled;
        document.getElementById('ir-threshold').value = rw.threshold || 70;
        document.getElementById('ir-threshold-badge').textContent = rw.threshold || 70;
        document.getElementById('ir-boost').value = rw.min_gain || 10;
        document.getElementById('ir-boost-badge').textContent = rw.min_gain || 10;
        document.getElementById('ir-critic').checked = !!rw.use_critic_agent;
        document.getElementById('ir-style-guard').checked = rw.style_guard_enabled !== false;
        document.getElementById('ir-ai-patterns').checked = rw.ai_patterns_check_enabled !== false;
        document.getElementById('ir-rewrite-model').value = rw.rewrite_model_id || 0;
        document.getElementById('ir-max-iter').value = ir.max_iterations || 3;
        document.getElementById('ir-max-iter-badge').textContent = ir.max_iterations || 3;
        document.getElementById('ir-min-improve').value = ir.min_improvement || 5;
        document.getElementById('ir-min-improve-badge').textContent = (ir.min_improvement || 5).toFixed(1);
        document.getElementById('ir-target').value = ir.target_score || 80;
        document.getElementById('ir-target-badge').textContent = (ir.target_score || 80).toFixed(1);
        document.getElementById('ir-decline').value = ir.quality_decline_threshold || 3;
        document.getElementById('ir-decline-badge').textContent = (ir.quality_decline_threshold || 3).toFixed(1);
        updateStatusInfo(rw.enabled);
        detectMode(cfg);
    }

    function formToCfg() {
        return {
            rewrite: {
                enabled: document.getElementById('ir-enabled').checked,
                threshold: parseInt(document.getElementById('ir-threshold').value),
                min_gain: parseInt(document.getElementById('ir-boost').value),
                iterative_mode: true,
                use_critic_agent: document.getElementById('ir-critic').checked,
                style_guard_enabled: document.getElementById('ir-style-guard').checked,
                ai_patterns_check_enabled: document.getElementById('ir-ai-patterns').checked,
                rewrite_model_id: parseInt(document.getElementById('ir-rewrite-model').value)
            },
            iterative_refinement: {
                max_iterations: parseInt(document.getElementById('ir-max-iter').value),
                min_improvement: parseFloat(document.getElementById('ir-min-improve').value),
                target_score: parseFloat(document.getElementById('ir-target').value),
                quality_decline_threshold: parseFloat(document.getElementById('ir-decline').value)
            }
        };
    }

    function updateStatusInfo(enabled) {
        var el = document.getElementById('ir-status-text');
        var info = document.getElementById('ir-status-info');
        if (enabled) {
            el.textContent = '迭代重写已开启 — 写作完成后将自动检测质量并进行迭代改进';
            info.className = 'alert alert-success-subtle border-0 small mb-0';
        } else {
            el.textContent = '迭代重写已关闭 — 写作完成后不会自动进行质量改进';
            info.className = 'alert alert-secondary-subtle border-0 small mb-0';
        }
    }

    function detectMode(cfg) {
        var modeBtns = document.querySelectorAll('.ir-mode-btn');
        var currentMode = 'custom';
        for (var m in IR_MODES) {
            var preset = IR_MODES[m].config;
            if (cfg.rewrite.threshold === preset.rewrite.threshold &&
                cfg.rewrite.min_gain === preset.rewrite.min_gain &&
                cfg.rewrite.use_critic_agent === preset.rewrite.use_critic_agent &&
                cfg.iterative_refinement.max_iterations === preset.iterative_refinement.max_iterations &&
                cfg.iterative_refinement.min_improvement === preset.iterative_refinement.min_improvement &&
                cfg.iterative_refinement.target_score === preset.iterative_refinement.target_score &&
                cfg.iterative_refinement.quality_decline_threshold === preset.iterative_refinement.quality_decline_threshold) {
                currentMode = m;
                break;
            }
        }
        modeBtns.forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.mode === currentMode);
        });
        var maxIter = cfg.iterative_refinement.max_iterations || 3;
        document.getElementById('ir-current-mode').textContent =
            currentMode === 'custom' ? '自定义' : IR_MODES[currentMode].label;
        document.getElementById('ir-token-est').textContent =
            currentMode === 'custom' ? '~' + maxIter + 'x Token' : IR_MODES[currentMode].token;
        var customCount = 0;
        if (currentMode !== 'custom') {
            document.getElementById('ir-custom-count').textContent = '0';
        }
    }

    function applyMode(mode) {
        if (!IR_MODES[mode]) return;
        cfgToForm(IR_MODES[mode].config);
        var desc = document.getElementById('ir-mode-desc');
        desc.style.display = '';
        document.getElementById('ir-mode-desc-text').textContent =
            '已切换到' + IR_MODES[mode].label + '，所有参数已自动配置。点击「保存」使其生效。';
    }

    document.querySelectorAll('.ir-mode-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            applyMode(this.dataset.mode);
        });
    });

    document.getElementById('ir-enabled').addEventListener('change', function() {
        updateStatusInfo(this.checked);
    });

    // 迭代重写配置为小说级存储（审计修复 2026-07-19 M3-NEW3/H-中10），
    // 加载与保存都必须携带当前选择的目标小说 ID。
    function currentIrNovelId() {
        var sel = document.getElementById('ir-novel-select');
        return sel ? (parseInt(sel.value, 10) || 0) : 0;
    }

    function loadIterativeSettings() {
        var novelId = currentIrNovelId();
        if (novelId <= 0) return;
        fetch(apiRouteUrl('iterative_config', 'action=get&novel_id=' + novelId))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    cfgToForm(data.data);
                }
            })
            .catch(function(err) {
                console.error('加载迭代配置失败:', err);
            });
    }

    window.saveIterativeSettings = function() {
        var novelId = currentIrNovelId();
        if (novelId <= 0) {
            alert('保存失败：请先创建并选择一部小说');
            return;
        }
        var btn = document.getElementById('ir-save-btn');
        var origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中...';
        var cfg = formToCfg();
        fetch(apiRouteUrl('iterative_config', 'action=update&novel_id=' + novelId), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(cfg)
        }).then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            if (data.success) {
                var toast = document.createElement('div');
                toast.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3 shadow';
                toast.style.zIndex = '9999';
                toast.innerHTML = '<i class="bi bi-check-circle me-2"></i>迭代重写配置已保存';
                document.body.appendChild(toast);
                setTimeout(function() { toast.remove(); }, 2500);
                if (data.data) cfgToForm(data.data);
            } else {
                alert('保存失败：' + (data.error || data.message || '未知错误'));
            }
        }).catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            alert('保存失败：' + err.message);
        });
    };

    window.resetIterativeSettings = function() {
        if (!confirm('确定将迭代重写配置重置为默认值？')) return;
        fetch(apiRouteUrl('iterative_config', 'action=defaults'))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    cfgToForm(data.data);
                    var toast = document.createElement('div');
                    toast.className = 'alert alert-info position-fixed top-0 start-50 translate-middle-x mt-3 shadow';
                    toast.style.zIndex = '9999';
                    toast.innerHTML = '<i class="bi bi-arrow-counterclockwise me-2"></i>已重置为默认值，请点击「保存」使其生效';
                    document.body.appendChild(toast);
                    setTimeout(function() { toast.remove(); }, 2500);
                }
            })
            .catch(function(err) {
                alert('重置失败：' + err.message);
            });
    };

    var iterativeTab = document.getElementById('iterative-tab');
    if (iterativeTab) {
        iterativeTab.addEventListener('shown.bs.tab', function() {
            loadIterativeSettings();
        });
    }
    var irNovelSelect = document.getElementById('ir-novel-select');
    if (irNovelSelect) {
        irNovelSelect.addEventListener('change', loadIterativeSettings);
    }
    if (document.getElementById('iterative-tab-pane')?.classList.contains('show')) {
        // app.js（apiRouteUrl）在 pageFooter 中加载；默认直接打开本标签时延后到
        // DOMContentLoaded，避免页面脚本早于统一路由 helper 执行。
        if (typeof apiRouteUrl === 'function') {
            loadIterativeSettings();
        } else {
            document.addEventListener('DOMContentLoaded', loadIterativeSettings, { once: true });
        }
    }
})();
