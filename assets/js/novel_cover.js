// 小说详情页（novel.php）：封面管理。由 novel.php 内联脚本原样外置。
// ── 封面管理弹窗 ──────────────────────────────────────────────
function showCoverModal(novelId) {
    var existing = document.getElementById('coverManageModal');
    if (existing) existing.remove();

    var currentCover = NOVEL_PAGE_DATA.coverImage;
    var coverColor = NOVEL_PAGE_DATA.coverColor;
    var title = NOVEL_PAGE_DATA.title;

    var previewHtml = currentCover
        ? '<img src="' + _escAttr(currentCover) + '?t=' + Date.now() + '" style="width:100%;height:100%;object-fit:cover;border-radius:8px">'
        : '<div style="width:100%;height:100%;background:linear-gradient(135deg,' + _escAttr(coverColor) + ',' + _escAttr(coverColor) + '99);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:2rem;font-weight:700">' + _escAttr(title.substring(0, 4)) + '</div>';

    var modal = document.createElement('div');
    modal.id = 'coverManageModal';
    modal.className = 'modal fade';
    modal.innerHTML = `
    <div class="modal-dialog">
      <div class="modal-content bg-dark text-light">
        <div class="modal-header border-secondary">
          <h5 class="modal-title"><i class="bi bi-image me-2"></i>管理封面 - ${_escAttr(title)}</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div style="width:200px;height:267px;margin:0 auto 16px;overflow:hidden;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.3)" id="cover-modal-preview">${previewHtml}</div>

          <div class="mb-3">
            <label class="form-label small text-muted">上传封面图片</label>
            <div class="d-flex gap-2">
              <input type="file" class="form-control form-control-sm" id="cover-modal-file" accept="image/jpeg,image/png,image/webp" style="max-width:280px">
              <button type="button" class="btn btn-sm btn-outline-primary" data-csp-click="novel.upload-cover" data-novel-id="${Number(novelId)}"><i class="bi bi-upload me-1"></i>上传</button>
            </div>
            <div class="form-text">推荐分辨率 1086×1448，支持 JPG/PNG/WebP</div>
          </div>

          <div class="mb-3">
            <label class="form-label small text-muted">AI 生成封面（gpt-image-2）</label>
            <div class="input-group input-group-sm">
              <input type="text" class="form-control" id="cover-modal-keyword" placeholder="输入封面描述关键词">
              <button type="button" class="btn btn-outline-info" data-csp-click="novel.generate-cover" data-novel-id="${Number(novelId)}"><i class="bi bi-stars me-1"></i>生成</button>
            </div>
          </div>

          <div id="cover-modal-status" class="small" style="display:none"></div>

          ${currentCover ? '<button type="button" class="btn btn-sm btn-outline-danger mt-2" data-csp-click="novel.delete-cover" data-novel-id="' + Number(novelId) + '"><i class="bi bi-trash me-1"></i>移除当前封面</button>' : ''}
        </div>
      </div>
    </div>`;
    document.body.appendChild(modal);
    new bootstrap.Modal(modal).show();
}

// 审计 P0：封面路径 / 标题等来自服务端响应或持久化数据，拼接进 innerHTML 的属性或
// 文本前统一转义，防止属性击穿 / 标签注入造成 XSS。覆盖 & < > " ' 五类字符。
function _escAttr(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function uploadCoverFromModal(novelId) {
    var fileInput = document.getElementById('cover-modal-file');
    if (!fileInput.files || !fileInput.files[0]) { alert('请先选择图片'); return; }

    var status = document.getElementById('cover-modal-status');
    status.style.display = '';
    status.className = 'small text-info';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>上传中...';

    var fd = new FormData();
    fd.append('action', 'upload');
    fd.append('novel_id', novelId);
    fd.append('cover_file', fileInput.files[0]);

    fetch(apiRouteUrl('cover_actions'), { method: 'POST', body: fd, headers: {'X-CSRF-Token': getCsrf()} })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            status.className = 'small text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + escHtml(data.msg);
            document.getElementById('cover-modal-preview').innerHTML = '<img src="' + _escAttr(data.path) + '?t=' + Date.now() + '" style="width:100%;height:100%;object-fit:cover;border-radius:8px">';
            // 更新主页面封面
            var mainCover = document.querySelector('.novel-cover-sm');
            if (mainCover) {
                mainCover.style.background = 'none';
                mainCover.innerHTML = '<img src="' + _escAttr(data.path) + '?t=' + Date.now() + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">';
            }
        } else {
            status.className = 'small text-danger';
            status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + escHtml(data.msg || '上传失败');
        }
    })
    .catch(err => {
        status.className = 'small text-danger';
        status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + escHtml(err.message);
    });
}

function generateCoverFromModal(novelId) {
    var keyword = document.getElementById('cover-modal-keyword').value.trim();
    if (!keyword) { alert('请输入封面描述关键词'); return; }

    var status = document.getElementById('cover-modal-status');
    status.style.display = '';
    status.className = 'small text-info';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>正在生成封面，请稍候（约30秒）...';

    fetch(apiRouteUrl('cover_actions'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf()},
        body: JSON.stringify({ action: 'generate', novel_id: novelId, keyword: keyword })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            status.className = 'small text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + escHtml(data.msg);
            document.getElementById('cover-modal-preview').innerHTML = '<img src="' + _escAttr(data.path) + '?t=' + Date.now() + '" style="width:100%;height:100%;object-fit:cover;border-radius:8px">';
            var mainCover = document.querySelector('.novel-cover-sm');
            if (mainCover) {
                mainCover.style.background = 'none';
                mainCover.innerHTML = '<img src="' + _escAttr(data.path) + '?t=' + Date.now() + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">';
            }
        } else {
            status.className = 'small text-danger';
            status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + escHtml(data.msg || '生成失败');
        }
    })
    .catch(err => {
        status.className = 'small text-danger';
        status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + escHtml(err.message);
    });
}

function deleteCoverFromModal(novelId) {
    if (!confirm('确定移除当前封面图片？')) return;

    var status = document.getElementById('cover-modal-status');
    status.style.display = '';
    status.className = 'small text-info';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>删除中...';

    fetch(apiRouteUrl('cover_actions'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf()},
        body: JSON.stringify({ action: 'delete', novel_id: novelId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            status.className = 'small text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>封面已移除';
            var coverColor = NOVEL_PAGE_DATA.coverColor;
            var title = NOVEL_PAGE_DATA.title;
            document.getElementById('cover-modal-preview').innerHTML = '<div style="width:100%;height:100%;background:linear-gradient(135deg,' + _escAttr(coverColor) + ',' + _escAttr(coverColor) + '99);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:2rem;font-weight:700">' + _escAttr(title.substring(0, 4)) + '</div>';
            var mainCover = document.querySelector('.novel-cover-sm');
            if (mainCover) {
                mainCover.style.background = 'linear-gradient(135deg,' + _escAttr(coverColor) + ',' + _escAttr(coverColor) + '99)';
                mainCover.textContent = title.substring(0, 4);
            }
        } else {
            status.className = 'small text-danger';
            status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + escHtml(data.msg || '删除失败');
        }
    })
    .catch(err => {
        status.className = 'small text-danger';
        status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + escHtml(err.message);
    });
}

