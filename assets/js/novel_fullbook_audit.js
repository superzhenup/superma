// 小说详情页（novel.php）：全书体检报告。由 novel.php 内联脚本原样外置。
(function(){
  var modalEl = document.getElementById('fullbookAuditModal');
  if (!modalEl) return;
  function esc(s){ return (s||'').replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];}); }
  modalEl.addEventListener('shown.bs.modal', function(){
    var body = document.getElementById('fullbook-audit-body');
    body.innerHTML = '<div class="text-center text-muted py-4">加载中…</div>';
    apiPost('actions', { action: 'get_fullbook_audits', novel_id: NOVEL_ID, limit: 10 })
      .then(function(res){
        var audits = (res && res.data && res.data.audits) || [];
        if (!audits.length) {
          body.innerHTML = '<div class="text-center text-muted py-4">尚无体检报告。写满 50 章后自动生成。</div>';
          return;
        }
        body.innerHTML = audits.map(function(a){
          var sev = {high:'🔴',medium:'🟠',low:'🟡'};
          var issues = (a.issues||[]).map(function(i){
            return '<li>'+(sev[i.severity]||'🟡')+' '+esc(i.desc||'')+'</li>';
          }).join('');
          var scoreBadge = a.score!=null
            ? '<span class="badge '+(a.score>=8?'bg-success':a.score>=6?'bg-warning text-dark':'bg-danger')+'">'+a.score+'/10</span>'
            : '';
          return '<div class="mb-3 p-3 rounded border border-secondary">'
            + '<div class="d-flex justify-content-between align-items-center mb-2">'
            + '<strong>第 '+a.chapter_number+' 章体检</strong>'
            + scoreBadge+'</div>'
            + (a.verdict?('<div class="small text-info mb-2">结论：'+esc(a.verdict)+'</div>'):'')
            + (issues?('<ul class="small mb-1" style="padding-left:1.1rem">'+issues+'</ul>'):'<div class="small text-muted">无明显问题</div>')
            + '<div class="small text-muted mt-1">'+esc(a.created_at||'')+'</div>'
            + '</div>';
        }).join('');
      })
      .catch(function(err){
        body.innerHTML = '<div class="text-danger py-3">加载失败：'+esc(err.message||'')+'</div>';
      });
  });
})();
