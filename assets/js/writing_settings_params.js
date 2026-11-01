// 写作设置页（writing_settings.php）：基础生成参数（保存/重置/紧急停止代际）与页内提示。由内联脚本原样外置。
function saveWsParams() {
    const container = document.getElementById('ws-params-container');
    const inputs = container.querySelectorAll('input[name], select[name]');
    const body = new FormData();
    body.append('action', 'save');
    // 后端 csrf_verify()/csrf_verify_api() 识别 $_POST['_token'] 或 X-CSRF-Token 头
    //（审计 P3-F：原字段名 csrf_token 后端不识别，此前全靠 layout 全局 fetch 包装注入头才通过）
    body.append('_token', (window.CSRF_TOKEN || ''));
    inputs.forEach(el => {
        if (el.disabled) return;
        if (el.type === 'range' || el.type === 'number' || el.type === 'text' || el.type === 'time' || el.tagName === 'SELECT') {
            body.append(el.name, el.value);
        }
    });
    fetch(apiRouteUrl('writing_params', 'action=save'), { method: 'POST', body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast(data.message || '保存成功', 'success');
            } else {
                showToast(data.message || data.error || '保存失败', 'danger');
            }
        })
        .catch(function() { showToast('请求失败', 'danger'); });
}

function resetWsParams() {
    if (!confirm('确定要将所有写作参数重置为系统默认值吗？')) return;
    const body = new FormData();
    body.append('action', 'reset');
    body.append('_token', (window.CSRF_TOKEN || ''));
    fetch(apiRouteUrl('writing_params', 'action=reset'), { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || '已重置', 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.message || '重置失败', 'danger');
            }
        })
        .catch(() => showToast('请求失败', 'danger'));
}

function bumpHarnessEmergencyEpoch() {
    if (!confirm('确定递增紧急停止代际吗？所有使用旧代际的在途 Harness 写作都会在下一个安全检查点停止；此操作不可撤销。')) return;
    const body = new FormData();
    body.append('_token', (window.CSRF_TOKEN || ''));
    fetch(apiRouteUrl('writing_params', 'action=bump_harness_emergency_epoch'), { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || '紧急停止代际已递增', 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.message || data.error || '递增失败', 'danger');
            }
        })
        .catch(() => showToast('请求失败', 'danger'));
}

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-bg-${type} border-0 show`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}
