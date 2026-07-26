(function(){
  'use strict';

  var API = 'api/index.php?route=hot_novels';

  var state = {
    source: 'all',
    channel: 'all',
    category: 'all',
    rank_type: 'all',
    days: 7,
    keyword: '',
    page: 1,
    pageSize: 24,
  };

  // 审计修复 M-3（2026-06-22）：统一 api() 错误处理，与 app.js 的 _safeJson 模式一致。
  // 原实现 throw Error，但调用方无 .catch，会导致未处理 Promise 拒绝。
  function api(action, data, method) {
    method = method || 'POST';
    var opts = { method: method, headers: { 'Content-Type': 'application/json' } };
    if (method !== 'GET' && method !== 'HEAD') {
      opts.headers['X-CSRF-Token'] = (window.CSRF_TOKEN || '');
      opts.body = JSON.stringify(data || {});
    }
    return fetch(API + '&action=' + action, opts).then(function(r){
      if (!r.ok) {
        return r.json().catch(function(){
          return { ok: false, msg: '请求失败（HTTP ' + r.status + '）', error: 'http_error' };
        }).then(function(d){
          if (d && typeof d === 'object' && 'ok' in d) return d;
          return { ok: false, msg: (d && d.msg) || ('请求失败（HTTP ' + r.status + '）'), error: 'http_error' };
        });
      }
      return r.json().catch(function(){
        return { ok: false, msg: '服务器返回了非 JSON 响应', error: 'invalid_json' };
      });
    }).catch(function(){
      return { ok: false, msg: '网络请求失败，请检查网络连接', error: 'network_error' };
    });
  }

  // 审计修复 M-2（2026-06-22）：统一为正则转义 5 字符，与 app.js/knowledge.js 一致。
  // 原 DOM textContent 方式不转义引号，用于属性上下文有 XSS 风险。
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(m){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
  }

  // 仅放行 http(s) 链接进入 href，阻断 javascript:/data: 等危险协议（防存储型 XSS）。
  // esc() 只做 HTML 实体编码，"javascript:..." 不含被转义字符会原样进入 href，故此处单独校验协议。
  function safeUrl(u){
    u = String(u == null ? '' : u).trim();
    return /^https?:\/\//i.test(u) ? u : '';
  }

  function fmtNum(n){
    n = Number(n) || 0;
    if (n >= 100000000) return (n/100000000).toFixed(1) + '亿';
    if (n >= 10000) return (n/10000).toFixed(1) + '万';
    return n.toLocaleString('zh-CN');
  }

  // ============== Tab 切换 ==============
  function bindTabs(containerId, key) {
    var c = document.getElementById(containerId);
    if (!c) return;
    c.addEventListener('click', function(e){
      var el = e.target.closest('.ht-tab');
      if (!el) return;
      c.querySelectorAll('.ht-tab').forEach(function(t){ t.classList.remove('active'); });
      el.classList.add('active');
      state[key] = el.dataset[key];
      state.page = 1;
      loadAll();
    });
  }

  bindTabs('sourceTabs', 'source');
  bindTabs('channelTabs', 'channel');
  bindTabs('categoryTabs', 'category');

  window.applyFilter = function(){
    state.rank_type = document.getElementById('filterRankType').value;
    state.days      = parseInt(document.getElementById('filterDays').value) || 7;
    state.keyword   = document.getElementById('filterKeyword').value.trim();
    state.page = 1;
    loadAll();
  };

  window.resetFilter = function(){
    state.source = 'all'; state.channel = 'all'; state.category = 'all';
    state.rank_type = 'all'; state.days = 7; state.keyword = '';
    state.page = 1;
    document.querySelectorAll('#sourceTabs .ht-tab, #channelTabs .ht-tab, #categoryTabs .ht-tab').forEach(function(el){
      el.classList.toggle('active', el.dataset.source === 'all' || el.dataset.channel === 'all' || el.dataset.category === 'all');
    });
    document.getElementById('filterDays').value = '7';
    document.getElementById('filterKeyword').value = '';
    loadAll();
  };

  // ============== 主加载 ==============
  function loadAll(){
    loadSummary();
    loadList();
  }

  function loadSummary(){
    api('summary', { source: state.source, days: state.days })
      .then(function(d){
        if (!d.ok) return;
        var data = d.data;
        renderSourceCounts(data.source_counts);
        renderRankTypes(data.rank_types);
        renderDashboard(data);
        renderRadar(data.category_dist || []);
        renderTrend(data.push_trend || []);
      });
  }

  function loadList(){
    var listEl = document.getElementById('novelList');
    listEl.innerHTML = '<div class="text-muted small">加载中…</div>';
    api('list', {
      source: state.source, channel: state.channel,
      category: state.category, rank_type: state.rank_type,
      days: state.days, keyword: state.keyword,
      page: state.page, page_size: state.pageSize,
    }).then(function(d){
      if (!d.ok) { listEl.innerHTML = '<div class="text-danger small">加载失败：' + esc(d.error||d.msg||'') + '</div>'; return; }
      renderList(d.data);
    });
  }

  // ============== Source tab 角标 ==============
  function renderSourceCounts(counts){
    if (!counts) return;
    ['all','qidian','fanqie','zongheng','qimao'].forEach(function(s){
      var el = document.getElementById('cnt-' + s);
      if (el) el.textContent = counts[s] || 0;
    });
  }

  function renderRankTypes(types){
    var sel = document.getElementById('filterRankType');
    var cur = sel.value;
    var html = '<option value="all">全部</option>';
    (types || []).forEach(function(t){
      html += '<option value="' + esc(t) + '">' + esc(t) + '</option>';
    });
    sel.innerHTML = html;
    sel.value = cur;
  }

  // ============== Dashboard ==============
  function renderDashboard(data){
    var lb = data.latest_batch;
    var hint = document.getElementById('latestBatchHint');
    if (lb) {
      hint.textContent = '· 最新批次：' + esc(lb.source) + ' · ' + esc(lb.created_at) + ' · 准备 ' + lb.prepared_items + ' 条';
    } else {
      hint.textContent = '· 暂无批次';
    }

    var html = '';
    var rt = data.recent_trend || { total_7d: 0, top_rising: [] };
    var rising = (rt.top_rising || []).map(function(r){
      return esc(r.category) + '(' + r.count + ')';
    }).join('、');
    html += '<div class="row-line"><span class="lbl">趋势：</span>近 7 天入库 <b style="color:#4ade80">' + rt.total_7d + '</b> 条';
    if (rising) html += '，上升：' + rising;
    html += '</div>';

    if (data.top_keywords && data.top_keywords.length) {
      html += '<div class="row-line"><span class="lbl">TOP 关键词：</span>';
      data.top_keywords.forEach(function(k){
        html += '<span class="kw-chip">' + esc(k.keyword) + ' · ' + k.count + '</span>';
      });
      html += '</div>';
    }

    if (data.category_dist && data.category_dist.length) {
      html += '<div class="row-line"><span class="lbl">题材分布：</span>';
      data.category_dist.forEach(function(c){
        html += '<span class="cat-chip">' + esc(c.category) + ' · ' + c.count + '</span>';
      });
      html += '</div>';

      html += '<div class="row-line"><span class="lbl">题材竞争度：</span>';
      data.category_dist.forEach(function(c){
        var lbl = c.competition === 'red' ? '红海'
                : c.competition === 'mainstream' ? '主流'
                : c.competition === 'normal' ? '常规' : '蓝海';
        html += '<span class="cat-chip ' + c.competition + '">' + esc(c.category) + ' · ' + lbl + '</span>';
      });
      html += '</div>';
      html += '<div class="text-muted small" style="font-size:.7rem;color:#707090;margin-top:.4rem">'
            + '● 红海(占比≥20%) ● 主流(10-20%) ● 常规(4-10%) ● 蓝海(<4%·适合差异化切入)</div>';
    }

    document.getElementById('dashboardContent').innerHTML = html || '<div class="text-muted small">暂无数据</div>';
  }

  // ============== SVG 雷达图 ==============
  function renderRadar(catDist){
    var box = document.getElementById('radarChart');
    var top = (catDist || []).slice(0, 8);
    if (top.length < 3) {
      box.innerHTML = '<div class="text-muted small" style="text-align:center;padding:2rem 0;color:#707090">题材数据不足</div>';
      return;
    }
    var W = 360, H = 240, cx = W/2, cy = H/2 + 10, R = 80;
    var max = Math.max.apply(null, top.map(function(c){ return c.count; }));
    var step = R / 4;

    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;max-height:240px">';

    // 同心圆背景
    for (var i = 1; i <= 4; i++) {
      svg += '<circle cx="' + cx + '" cy="' + cy + '" r="' + (step*i) + '" fill="none" stroke="#2d2d4e" stroke-width="0.5"/>';
    }

    // 轴线
    var n = top.length;
    var points = [];
    var labels = [];
    for (var k = 0; k < n; k++) {
      var ang = -Math.PI/2 + (Math.PI*2 * k / n);
      var x1 = cx + Math.cos(ang) * R;
      var y1 = cy + Math.sin(ang) * R;
      svg += '<line x1="' + cx + '" y1="' + cy + '" x2="' + x1 + '" y2="' + y1 + '" stroke="#2d2d4e" stroke-width="0.5"/>';

      var val = top[k].count / max;
      var px = cx + Math.cos(ang) * R * val;
      var py = cy + Math.sin(ang) * R * val;
      points.push(px + ',' + py);

      // 标签：放在略外的位置
      var lx = cx + Math.cos(ang) * (R + 18);
      var ly = cy + Math.sin(ang) * (R + 18);
      var anchor = 'middle';
      if (Math.cos(ang) > 0.3) anchor = 'start';
      else if (Math.cos(ang) < -0.3) anchor = 'end';
      labels.push('<text x="' + lx + '" y="' + (ly+4) + '" fill="#a5b4fc" font-size="10" text-anchor="' + anchor + '">' + esc(top[k].category) + '</text>');
    }

    svg += '<polygon points="' + points.join(' ') + '" fill="rgba(99,102,241,.25)" stroke="#818cf8" stroke-width="1.5"/>';

    // 顶点
    points.forEach(function(p){
      var xy = p.split(',');
      svg += '<circle cx="' + xy[0] + '" cy="' + xy[1] + '" r="2.5" fill="#ff8a4c"/>';
    });

    svg += labels.join('');
    svg += '</svg>';
    box.innerHTML = svg;
  }

  // ============== SVG 趋势线 ==============
  function renderTrend(trend){
    var box = document.getElementById('trendChart');
    if (!trend || trend.length === 0) {
      box.innerHTML = '<div class="text-muted small" style="text-align:center;padding:2rem 0;color:#707090">暂无推送数据</div>';
      return;
    }
    var W = 520, H = 200, pad = 30;
    var max = 0;
    trend.forEach(function(t){ max = Math.max(max, t.accepted + t.updated); });
    if (max === 0) max = 1;

    var stepX = (W - pad * 2) / Math.max(1, trend.length - 1);
    var ptsA = [], ptsU = [];
    for (var i = 0; i < trend.length; i++) {
      var x = pad + stepX * i;
      var yA = H - pad - (trend[i].accepted / max) * (H - pad * 2);
      var yU = H - pad - (trend[i].updated  / max) * (H - pad * 2);
      ptsA.push(x + ',' + yA);
      ptsU.push(x + ',' + yU);
    }

    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;max-height:200px">';
    // Y 网格
    for (var g = 0; g <= 4; g++) {
      var gy = pad + (H - pad*2) * g / 4;
      var gv = Math.round(max * (1 - g/4));
      svg += '<line x1="' + pad + '" y1="' + gy + '" x2="' + (W-pad) + '" y2="' + gy + '" stroke="#2d2d4e" stroke-width="0.5"/>';
      svg += '<text x="' + (pad-4) + '" y="' + (gy+3) + '" fill="#707090" font-size="9" text-anchor="end">' + gv + '</text>';
    }
    // X 标签（隔几个一显示）
    var labelStep = Math.max(1, Math.ceil(trend.length / 7));
    for (var j = 0; j < trend.length; j++) {
      if (j % labelStep !== 0 && j !== trend.length - 1) continue;
      var lx = pad + stepX * j;
      var d = trend[j].date.slice(5); // MM-DD
      svg += '<text x="' + lx + '" y="' + (H-pad+14) + '" fill="#707090" font-size="9" text-anchor="middle">' + d + '</text>';
    }

    // 数据线
    svg += '<polyline points="' + ptsU.join(' ') + '" fill="none" stroke="#fbbf24" stroke-width="2"/>';
    svg += '<polyline points="' + ptsA.join(' ') + '" fill="none" stroke="#4ade80" stroke-width="2"/>';
    // 点
    ptsA.forEach(function(p){ var xy = p.split(','); svg += '<circle cx="'+xy[0]+'" cy="'+xy[1]+'" r="2.5" fill="#4ade80"/>'; });
    ptsU.forEach(function(p){ var xy = p.split(','); svg += '<circle cx="'+xy[0]+'" cy="'+xy[1]+'" r="2.5" fill="#fbbf24"/>'; });

    svg += '</svg>';
    box.innerHTML = svg;
  }

  // ============== 列表 ==============
  var SOURCE_LABEL = { qidian: '起点', fanqie: '番茄', zongheng: '纵横', qimao: '七猫' };

  function renderList(data){
    var totalEl = document.getElementById('totalLine');
    totalEl.textContent = '共 ' + (data.total || 0) + ' 条 · 第 ' + data.page + '/' + Math.max(1, data.total_page) + ' 页';

    var listEl = document.getElementById('novelList');
    if (!data.items || data.items.length === 0) {
      listEl.innerHTML = '<div class="text-muted small" style="grid-column:1/-1;text-align:center;padding:3rem 0;color:#707090">'
                       + '没有匹配的数据。尝试切换 source/题材 或重置过滤器。</div>';
      renderPagination(0, 1);
      return;
    }

    var html = data.items.map(function(it){
      // 审计修复 S-2（2026-06-22）：服务端数值字段强制整数化，防止字符串注入突破 onclick/属性上下文
      var itemId = parseInt(it.id, 10) || 0;
      var rankNo = parseInt(it.rank_no, 10) || 0;
      var hotness = Number(it.hotness_score) || 0;
      var srcLbl = SOURCE_LABEL[it.source] || it.source;
      var tags = (it.tags || []).slice(0, 2).map(function(t){ return '<span class="tag">' + esc(t) + '</span>'; }).join('');
      return '<div class="ht-card" data-id="' + itemId + '">'
           +   '<div class="ht-card-head">'
           +     '<span class="src">' + esc(srcLbl) + (it.rank_type ? ' · ' + esc(it.rank_type) : '') + '</span>'
           +     '<span class="rank">No.' + rankNo + '</span>'
           +   '</div>'
           +   '<div class="ht-card-title">' + esc(it.title) + '</div>'
           +   '<div class="ht-card-meta">'
           +     '<span><i class="bi bi-person"></i>' + esc(it.author || '佚名') + '</span>'
           +     (it.raw_category ? '<span><i class="bi bi-bookmark"></i>' + esc(it.raw_category) + '</span>' : '')
           +   '</div>'
           +   '<div class="ht-card-hot"><i class="bi bi-fire"></i> ' + fmtNum(hotness) + '</div>'
           +   (tags ? '<div class="ht-card-tags">' + tags + '</div>' : '')
           + '</div>';
    }).join('');
    listEl.innerHTML = html;
    // 事件委托替代 inline onclick，避免 id 字符串注入
    listEl.onclick = function(e){
      var card = e.target.closest('.ht-card');
      if (!card) return;
      var id = parseInt(card.dataset.id, 10);
      if (id > 0) openDetail(id);
    };
    renderPagination(data.total_page, data.page);
  }

  function renderPagination(totalPage, current){
    // 审计修复 P1-7（2026-07-12）：强制类型转换，防止字符串型 totalPage/current 注入 onclick/innerHTML
    totalPage = parseInt(totalPage, 10) || 1;
    current   = parseInt(current, 10) || 1;
    var el = document.getElementById('pagination');
    if (totalPage <= 1) { el.innerHTML = ''; return; }
    var html = '';
    html += '<button ' + (current<=1?'disabled':'') + ' onclick="gotoPage(' + (current-1) + ')">‹</button>';

    var start = Math.max(1, current - 3);
    var end = Math.min(totalPage, current + 3);
    if (start > 1) html += '<button onclick="gotoPage(1)">1</button>' + (start > 2 ? '<span>…</span>' : '');
    for (var i = start; i <= end; i++) {
      html += '<button class="' + (i===current?'active':'') + '" onclick="gotoPage(' + i + ')">' + i + '</button>';
    }
    if (end < totalPage) html += (end < totalPage-1 ? '<span>…</span>' : '') + '<button onclick="gotoPage(' + totalPage + ')">' + totalPage + '</button>';

    html += '<button ' + (current>=totalPage?'disabled':'') + ' onclick="gotoPage(' + (current+1) + ')">›</button>';
    el.innerHTML = html;
  }

  window.gotoPage = function(p){
    state.page = p;
    loadList();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // ============== 详情 Modal ==============
  window.openDetail = function(id){
    document.getElementById('detailContent').innerHTML = '加载中…';
    showModal('detailModal');
    api('get', { id: id }).then(function(d){
      if (!d.ok) { document.getElementById('detailContent').innerHTML = '加载失败'; return; }
      renderDetail(d.data);
    });
  };

  function renderDetail(n){
    // 审计修复 S-2（2026-06-22）：数值字段强制整数化
    var rankNo = parseInt(n.rank_no, 10) || 0;
    var srcLbl = SOURCE_LABEL[n.source] || n.source;
    var tagsHtml = (n.tags || []).map(function(t){ return '<span style="background:#2d2d4e;color:#c8c8e0;padding:.15rem .5rem;border-radius:4px;font-size:.72rem;margin-right:.3rem">' + esc(t) + '</span>'; }).join('');

    var html = '';
    html += '<h4>' + esc(n.title) + '</h4>';
    html += '<div style="color:#9090b8;font-size:.85rem;margin-bottom:.8rem">'
         + '<span style="color:#818cf8">' + esc(srcLbl) + '</span>'
         + (n.rank_type ? ' · ' + esc(n.rank_type) + ' No.' + rankNo : '')
         + ' · 作者 <span style="color:#c8c8e0">' + esc(n.author || '佚名') + '</span>'
         + (n.raw_category ? ' · ' + esc(n.raw_category) : '')
         + '</div>';
    html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;font-size:.82rem;color:#9090b8;margin-bottom:.8rem">';
    html += '<div>字数：<b style="color:#c8c8e0">' + fmtNum(n.word_count) + '</b></div>';
    html += '<div>收藏：<b style="color:#c8c8e0">' + fmtNum(n.collect_count) + '</b></div>';
    html += '<div>推荐：<b style="color:#c8c8e0">' + fmtNum(n.recommend_count) + '</b></div>';
    html += '<div>热度：<b style="color:#ff8a4c">' + fmtNum(n.hotness_score) + '</b></div>';
    html += '</div>';
    if (tagsHtml) html += '<div style="margin-bottom:.6rem">' + tagsHtml + '</div>';
    var safeSourceUrl = safeUrl(n.source_url);
    if (safeSourceUrl) html += '<div style="margin-bottom:.6rem;font-size:.78rem"><a href="' + esc(safeSourceUrl) + '" target="_blank" rel="noopener noreferrer" style="color:#818cf8">官方页 ↗</a></div>';
    if (n.intro) html += '<div style="font-size:.85rem;color:#c8c8e0;line-height:1.7;background:#12122a;padding:.7rem;border-radius:8px;border-left:3px solid #6366f1">'
                       + esc(n.intro) + '</div>';

    if (n.analysis) {
      var a = n.analysis;
      var fields = [
        ['💎 卖点',      a.selling_points || a['卖点']],
        ['🎯 爽点',      a.appeals        || a['爽点']],
        ['🪤 套路',      a.tropes         || a['套路']],
        ['👥 受众',      a.audience       || a['受众']],
        ['⚠️ 风险',      a.risks          || a['风险']],
        ['💡 选题建议', a.suggestions     || a['选题建议']],
        ['🎬 开篇钩子', a.hooks           || a['开篇钩子']],
      ];
      html += '<h5 style="color:#a5b4fc;margin-top:1rem"><i class="bi bi-graph-up-arrow me-1"></i>爆款分析</h5>';
      html += '<div class="ht-analysis-grid">';
      fields.forEach(function(f){
        var val = f[1];
        if (!val) return;
        // 数组 → 换行分隔；对象 → JSON格式化；字符串 → 直接显示
        var display;
        if (Array.isArray(val)) {
          display = val.map(function(v){ return esc(v); }).join('<br>');
        } else if (typeof val === 'object') {
          display = esc(JSON.stringify(val, null, 1));
        } else {
          display = esc(val);
        }
        html += '<div class="ht-analysis-block"><div class="lbl">' + f[0] + '</div>' + display + '</div>';
      });
      // 对标作品：优先英文键，兼容中文键
      var benchmarks = a.benchmarks || a['对标作品'];
      if (benchmarks) {
        var bmArr = Array.isArray(benchmarks) ? benchmarks : String(benchmarks).split(/[、,]/);
        if (bmArr.length) {
          html += '<div class="ht-analysis-block" style="grid-column:1/-1;border-left-color:#fbbf24">'
                + '<div class="lbl">📚 对标作品</div>'
                + bmArr.map(function(b){ return '《' + esc(b.trim()) + '》'; }).join('、')
                + '</div>';
        }
      }
      html += '</div>';
      if (a.evidence) {
        html += '<details style="margin-top:.8rem;color:#9090b8;font-size:.78rem">'
              + '<summary style="cursor:pointer">证据链 (_evidence)</summary>'
              + '<pre style="background:#12122a;padding:.6rem;border-radius:6px;font-size:.7rem;color:#c8c8e0;white-space:pre-wrap;word-break:break-all">'
              + esc(JSON.stringify(a.evidence, null, 2))
              + '</pre></details>';
      }
    } else {
      html += '<div class="text-muted small" style="margin-top:1rem;color:#707090">本书暂无 AI 爆款分析数据</div>';
    }

    document.getElementById('detailContent').innerHTML = html;
  }

  // ============== Modal helpers ==============
  function showModal(id){ document.getElementById(id).classList.add('show'); document.body.style.overflow = 'hidden'; }
  window.closeModal = function(id){ document.getElementById(id).classList.remove('show'); document.body.style.overflow = ''; };

  // ============== 设置 Modal ==============
  window.openSettingsModal = function(){
    showModal('settingsModal');
    document.getElementById('settingsContent').innerHTML = '加载中…';
    api('get_settings', {}).then(function(d){
      if (!d.ok) { document.getElementById('settingsContent').innerHTML = '加载失败'; return; }
      renderSettings(d.data);
    });
  };

  function renderSettings(s){
    var keyDisplay = s.ingest_key
      ? '<code id="ingestKeyDisplay">' + esc(s.ingest_key) + '</code>'
      : '<span style="color:#707090">尚未生成密钥</span>';

    var html = '';
    html += '<div class="mb-3">'
         + '<div class="form-label mb-1">推送 URL</div>'
         + '<code style="display:block;padding:.4rem;background:#0f0f25;color:#a5b4fc;border-radius:6px;word-break:break-all">'
         + esc(s.ingest_url) + '</code>'
         + '<div style="color:#707090;margin-top:.3rem;font-size:.72rem">Agent 推送 POST 请求到此 URL</div>'
         + '</div>';

    html += '<div class="mb-3">'
         + '<div class="form-label mb-1 d-flex justify-content-between align-items-center">'
         + '<span>接入密钥 X-Ingest-Key</span>'
         + '<button class="btn btn-sm btn-primary" onclick="regenerateKey()"><i class="bi bi-arrow-clockwise me-1"></i>生成新密钥</button>'
         + '</div>'
         + '<div id="ingestKeyBox" style="padding:.4rem;background:#0f0f25;border-radius:6px;min-height:1.8rem">' + keyDisplay + '</div>'
         + '<div style="color:#707090;margin-top:.3rem;font-size:.72rem">'
         + '请求时放在 Header: <code style="background:#1a1a2e;padding:.1rem .3rem">X-Ingest-Key: &lt;密钥&gt;</code>。'
         + '点击"生成新密钥"会立即失效旧密钥。'
         + '</div></div>';

    html += '<div class="mb-3">'
         + '<div class="form-label mb-1">不收的题材（逗号分隔）</div>'
         + '<input type="text" class="form-control" id="setUnsupported" value="' + esc(s.unsupported_categories) + '">'
         + '<div style="color:#707090;margin-top:.3rem;font-size:.72rem">命中关键词的小说会被拒收，返回 UNSUPPORTED_CATEGORY</div>'
         + '</div>';

    html += '<div class="mb-3">'
         + '<div class="form-label mb-1">最低可信度（confidence_score 低于此值拒收）</div>'
         + '<input type="number" class="form-control" id="setMinConf" min="0" max="100" value="' + (parseInt(s.min_confidence, 10) || 50) + '" style="max-width:120px">'
         + '<div style="color:#707090;margin-top:.3rem;font-size:.72rem">默认 50。返回 LOW_CONFIDENCE</div>'
         + '</div>';

    html += '<div class="mb-3 form-check" style="color:#c8c8e0">'
         + '<input type="checkbox" class="form-check-input" id="setEnabled"' + (s.ingest_enabled ? ' checked' : '') + '>'
         + '<label class="form-check-label" for="setEnabled" style="margin-left:.4rem">启用推送接口（关闭后返回 503）</label>'
         + '</div>';

    html += '<div class="d-flex gap-2 mt-3">'
         + '<button class="btn btn-primary" onclick="saveSettings()">保存设置</button>'
         + '<button class="btn btn-outline-secondary" onclick="closeModal(\'settingsModal\')">关闭</button>'
         + '</div>';

    document.getElementById('settingsContent').innerHTML = html;
  }

  window.regenerateKey = function(){
    if (!confirm('生成新密钥会让旧密钥立即失效，确认继续？')) return;
    api('regenerate_key', {}).then(function(d){
      if (!d.ok) { alert('生成失败：' + (d.error||d.msg||'')); return; }
      document.getElementById('ingestKeyBox').innerHTML = '<code>' + esc(d.data.ingest_key) + '</code>';
    });
  };

  window.saveSettings = function(){
    var payload = {
      unsupported_categories: document.getElementById('setUnsupported').value,
      min_confidence: parseInt(document.getElementById('setMinConf').value) || 50,
      ingest_enabled: document.getElementById('setEnabled').checked ? 1 : 0,
    };
    api('save_settings', payload).then(function(d){
      if (!d.ok) { alert('保存失败：' + (d.error||d.msg||'')); return; }
      alert('已保存');
    });
  };

  // ============== 初始化 ==============
  document.getElementById('filterKeyword').addEventListener('keydown', function(e){
    if (e.key === 'Enter') applyFilter();
  });
  loadAll();
})();
