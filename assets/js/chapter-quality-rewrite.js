/* Explicit quality-rewrite candidates. Generation and status never replace the chapter. */
(function () {
    'use strict';
    const REQUEST_TIMEOUT = 15000;
    const POLL_DELAY = 5000;
    const MAX_WAIT = 2 * 60 * 60 * 1000;
    const MAX_UNAVAILABLE = 10;
    const STATES = ['queued', 'running', 'ready', 'adopting', 'adopted', 'failed', 'interrupted', 'unknown'];
    const STAGES = {
        queued: '等待后台启动', quality_check: '检查原稿质量', context_prepare: '整理章节上下文',
        model_request: '生成改写候选', quality_recheck: '复检改写候选', ready: '候选已就绪',
        adopting: '正在采纳', adopted: '已采纳', failed: '处理失败'
    };
    const state = { busy: false, chapterId: 0, taskId: '', task: null, candidate: '',
        candidateTaskId: '', uncertainStart: false, adoptionPending: false, requiresRefresh: false, reloaded: false };
    const recoveryAttempted = new Set();
    const el = id => document.getElementById(id);
    const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
    const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
    const taskIdValid = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
    const sourceHash = () => el('btn-quality-rewrite')?.dataset.sourceHash || '';
    const FIELD_LABELS = { 'edit-content': '正文', 'edit-title': '标题', 'outline-outline': '大纲',
        'outline-keypoints': '大纲', 'outline-hook': '大纲' };
    const unsavedFields = () => Object.keys(FIELD_LABELS)
        .filter(id => { const field = el(id); return field && field.value !== field.defaultValue; });
    const dirty = () => !el('edit-content') || unsavedFields().length > 0;
    // 压缩 / 润色 / 重新写作 / 回滚都在服务器已保存的版本上操作，完成后整页刷新；
    // 编辑框里未保存的修改既不参与、也不进版本历史，必须先拦下。
    function blockIfUnsaved(action) {
        const labels = [...new Set(unsavedFields().map(id => FIELD_LABELS[id]))];
        if (!labels.length) return false;
        alert('编辑框有未保存的' + labels.join('、') + '。' + action + '基于服务器上已保存的版本，完成后页面会刷新，'
            + '这些修改会丢失，也不会进入版本历史。请先保存或还原后再' + action + '。');
        return true;
    }
    function note(message) { if (el('rewriteStats')) el('rewriteStats').textContent = message; }
    function error(message, uncertain = false) {
        const result = new Error(message); result.uncertain = uncertain; return result;
    }
    function open() {
        const modal = el('rewriteModal');
        if (!modal || !el('rewriteContent') || !el('quality-rewrite-actions')) {
            throw error('改写对话框未加载，请刷新页面后重试');
        }
        modal.dataset.operation = 'quality-rewrite';
        el('quality-rewrite-actions').hidden = false;
        const instance = bootstrap.Modal.getOrCreateInstance
            ? bootstrap.Modal.getOrCreateInstance(modal)
            : (bootstrap.Modal.getInstance?.(modal) || new bootstrap.Modal(modal));
        instance.show();
    }
    function render() {
        const modal = el('rewriteModal');
        if (!modal || modal.dataset.operation !== 'quality-rewrite') return;
        const task = state.task;
        const ready = task?.state === 'ready' && task.active === false;
        const candidateReady = ready && state.candidateTaskId === state.taskId && state.candidate.trim() !== '';
        const canAdopt = candidateReady && hash(task.candidate_hash) && task.source_hash === sourceHash()
            && !task.conflict && !state.requiresRefresh;
        const stopped = task && task.active === false && ['failed', 'interrupted'].includes(task.state);
        const title = modal.querySelector('.modal-title');
        if (title) title.textContent = ready ? '改写候选已生成（尚未采纳）'
            : task?.state === 'adopted' ? '改写已采纳'
            : state.adoptionPending ? '正在核对采纳结果' : '质量改写';
        if (state.candidate) el('rewriteContent').textContent = state.candidate;
        else el('rewriteContent').textContent = task?.state === 'adopted' ? '正文已保存。' : '候选生成后会显示在这里，现有正文保持不变。';
        if (el('rewriteSpinner')) el('rewriteSpinner').style.display = state.busy && !ready ? '' : 'none';
        const adopt = el('btn-quality-adopt'), again = el('btn-quality-again'), resume = el('btn-quality-resume');
        if (adopt) { adopt.hidden = !candidateReady; adopt.disabled = state.busy || !canAdopt; }
        if (again) { again.hidden = !(ready || stopped); again.disabled = state.busy; }
        if (resume) { resume.hidden = !task || ready || stopped || task.state === 'adopted'; resume.disabled = state.busy; }
        if (ready) {
            const score = value => typeof value === 'number' ? value : value?.total_score;
            const before = score(task.quality_before), after = score(task.quality_after);
            const scores = Number.isFinite(before) && Number.isFinite(after) ? `；质量评分 ${Math.round(before)} → ${Math.round(after)}` : '';
            note(canAdopt ? `候选 ${Number(task.words) || 0} 字${scores}。请检查后采纳，或再次改写。`
                : '候选所依据的正文与当前页面不一致，或缺少完整结果；请刷新核对后再采纳。');
        } else if (task) {
            note(task.message || `${STAGES[task.stage] || '正在核对后台状态'}；任务 ${state.taskId}`);
        }
    }
    function controls(disabled) {
        const nodes = document.querySelectorAll('#btn-quality-rewrite, #btn-polish, #btn-compress, #btn-rewrite, '
            + '#btn-inline-polish, #btn-quality-adopt, #btn-quality-again, #btn-quality-resume, '
            + '[data-csp-click="chapter.save"], [data-csp-click="chapter.quality-check"]');
        const saved = [...nodes].map(node => [node, node.disabled]);
        if (disabled) for (const [node] of saved) node.disabled = true;
        return () => { for (const [node, previous] of saved) node.disabled = previous; };
    }
    async function operation(callback) {
        if (state.busy) return;
        state.busy = true;
        const restore = controls(true);
        try { await callback(); }
        catch (problem) { render(); note(problem.message || '操作失败，请重试'); }
        finally { state.busy = false; restore(); renderButtons(); }
    }
    // Refresh controls without overwriting a useful network/conflict message.
    function renderButtons() {
        const text = el('rewriteStats')?.textContent || '';
        render(); note(text);
    }
    async function request(action, taskId) {
        const controller = new AbortController();
        let timer;
        const body = { action, chapter_id: state.chapterId };
        if (taskId) body.task_id = taskId;
        if (action === 'start') body.expected_content_hash = sourceHash();
        const timeout = new Promise((resolve, reject) => {
            timer = setTimeout(() => { controller.abort(); reject(error('请求超时，尚未确认后台结果', true)); }, REQUEST_TIMEOUT);
        });
        const fetchResult = (async () => {
            let response;
            try {
                response = await fetch(apiRouteUrl('chapter_rewrite'), {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                    body: JSON.stringify(body), signal: controller.signal, cache: 'no-store'
                });
            } catch (_) { throw error('网络连接异常，尚未确认后台结果', true); }
            let payload;
            try { payload = await response.json(); }
            catch (_) { throw error('后台返回了无法识别的响应，请继续核对状态', true); }
            if (!response.ok || payload?.ok !== true) {
                const problem = error(payload?.msg || payload?.message || payload?.error || `请求失败（HTTP ${response.status}）`, !payload || response.status >= 500);
                problem.code = payload?.code;
                const reported = payload?.data?.task;
                if (reported && reported.task_id === taskId && STATES.includes(reported.state)
                    && [true, false, null].includes(reported.active)) problem.task = reported;
                throw problem;
            }
            if (!payload.data || !Object.prototype.hasOwnProperty.call(payload.data, 'task')) {
                throw error('响应缺少任务状态，尚未确认后台结果', true);
            }
            const task = payload.data.task;
            if (task === null) return null;
            if (!task || !taskIdValid(task.task_id) || (action !== 'start' && taskId && task.task_id !== taskId)
                || !STATES.includes(task.state) || ![true, false, null].includes(task.active)) {
                throw error('任务状态或编号不一致，已停止操作，请继续核对', true);
            }
            return task;
        })();
        try { return await Promise.race([fetchResult, timeout]); }
        finally { clearTimeout(timer); }
    }
    function receive(task) {
        if (!task) return;
        state.task = task; state.taskId = task.task_id;
        if (task.conflict && task.source_hash === sourceHash()) state.requiresRefresh = true;
        if (task.state === 'ready' && typeof task.content === 'string' && task.content.trim()) {
            state.candidate = task.content; state.candidateTaskId = task.task_id;
        }
        if (task.state !== 'unknown') state.uncertainStart = false;
        render();
    }
    function settled() {
        const task = state.task;
        if (!task || task.active !== false) return false;
        if (task.state === 'adopted') {
            state.adoptionPending = false;
            if (dirty()) {
                note('改写已采纳。编辑框还有未保存的修改，请先保留这些修改，再刷新查看已采纳的正文。');
                return true;
            }
            note('改写已采纳，正在刷新正文...');
            if (!state.reloaded) { state.reloaded = true; location.reload(); }
            return true;
        }
        if (['ready', 'failed', 'interrupted'].includes(task.state)) {
            state.adoptionPending = false;
            return true;
        }
        return false;
    }
    async function recoverAdoption() {
        if (state.task?.state !== 'adopted' || state.task.active !== false
            || !state.task.recovery_required || recoveryAttempted.has(state.taskId)) return;
        recoveryAttempted.add(state.taskId);
        note('正文已采纳，正在补齐保存回执...');
        try { receive(await request('adopt', state.taskId)); }
        catch (_) { note('正文已采纳；保存回执收尾尚未确认，请刷新查看正文。'); }
    }
    async function poll() {
        const began = Date.now();
        let unavailable = 0;
        while (Date.now() - began < MAX_WAIT) {
            await recoverAdoption();
            if (settled()) return;
            try {
                const task = await request('status', state.taskId);
                if (task) receive(task);
                const known = task && task.state !== 'unknown' && task.active !== null;
                unavailable = known ? 0 : unavailable + 1;
                if (known) { await recoverAdoption(); if (settled()) return; }
            } catch (problem) { unavailable++; note(problem.message + '；正在查询同一任务'); }
            if (unavailable >= MAX_UNAVAILABLE) break;
            await wait(POLL_DELAY);
        }
        note('暂时无法确认任务是否完成，已停止自动等待。请点击“继续查询”；候选和任务编号已保留，没有重新生成。');
    }
    function assertEditor() {
        const editor = el('edit-content');
        if (!editor || !editor.value.trim()) throw error('章节内容为空，无法改写');
        if (dirty()) throw error('编辑框有未保存的正文、标题或大纲。请先保存或还原，并刷新页面后再改写。');
        if (state.requiresRefresh) throw error('正文已在其他位置变化，请刷新页面后再次改写；当前候选仍保留。');
        if (!hash(sourceHash())) throw error('缺少正文版本标识，请刷新页面后重试');
    }
    function newTaskId() {
        if (!window.crypto?.getRandomValues) throw error('浏览器无法生成安全任务编号，请更换浏览器后重试');
        return [...window.crypto.getRandomValues(new Uint8Array(16))].map(byte => byte.toString(16).padStart(2, '0')).join('');
    }
    async function submit(newIdentity) {
        assertEditor();
        if (newIdentity) state.taskId = newTaskId();
        state.adoptionPending = false;
        state.uncertainStart = true;
        state.task = { task_id: state.taskId, state: 'unknown', stage: 'queued', active: null };
        render(); note('正在提交改写任务；现有正文不会被替换。');
        try {
            const task = await request('start', state.taskId);
            if (task) {
                const existing = task.task_id !== state.taskId;
                receive(task);
                if (existing) note('该章节已有改写任务，正在显示现有任务；没有重复启动。');
            }
        } catch (problem) {
            if (problem.code === 'chapter_changed') state.requiresRefresh = true;
            if (!problem.uncertain) {
                // A rejected submission may race another tab; retain its identity
                // and use status before any later attempt to start work.
                note(problem.message);
                return;
            }
            note(problem.message + '；正在核对同一任务');
        }
        await poll();
    }
    async function start(chapterId) {
        if (state.busy) return;
        try {
            if (!Number.isInteger(chapterId) || chapterId < 1
                || Number(el('btn-quality-rewrite')?.dataset.chapterId) !== chapterId) throw error('章节编号无效，请刷新页面');
            assertEditor(); state.chapterId = chapterId; open();
        } catch (problem) { if (typeof showToast === 'function') showToast(problem.message, 'warning'); else alert(problem.message); return; }
        return operation(async () => {
            note('正在核对已有改写任务...');
            const existing = await request('status', state.uncertainStart ? state.taskId : '');
            if (existing && (existing.active !== false || ['queued', 'running', 'ready', 'adopting', 'unknown'].includes(existing.state)
                || (existing.state === 'adopted' && (existing.recovery_required
                    || (hash(existing.current_hash) && existing.current_hash !== sourceHash()))))) {
                receive(existing); await poll(); return;
            }
            await submit(!state.uncertainStart);
        });
    }
    async function again() {
        if (state.busy || !state.task || state.task.active !== false
            || !['ready', 'failed', 'interrupted'].includes(state.task.state)) return;
        return operation(async () => { assertEditor(); await submit(true); });
    }
    async function resume() {
        if (state.busy || !taskIdValid(state.taskId)) return;
        return operation(async () => {
            const task = await request('status', state.taskId);
            if (task) receive(task);
            else if (state.uncertainStart) { await submit(false); return; }
            await poll();
        });
    }
    async function adopt() {
        if (state.busy || state.task?.state !== 'ready' || state.task.active !== false
            || state.candidateTaskId !== state.taskId || !state.candidate.trim()
            || !hash(state.task.candidate_hash) || state.task.source_hash !== sourceHash()
            || state.task.conflict || state.requiresRefresh) return;
        return operation(async () => {
            assertEditor(); state.adoptionPending = true;
            state.task = { ...state.task, state: 'adopting', active: null };
            render(); note('正在采纳候选；若响应中断，会先核对保存结果。');
            try { receive(await request('adopt', state.taskId)); }
            catch (problem) {
                if (problem.code === 'chapter_changed') state.requiresRefresh = true;
                if (problem.task) receive(problem.task);
                note(problem.message + '；正在核对采纳结果');
            }
            await poll();
        });
    }
    function prepareOtherOperation() {
        if (state.busy) { note('质量改写正在处理中，请先等待当前操作结束'); return false; }
        const modal = el('rewriteModal');
        if (modal) modal.dataset.operation = '';
        if (el('quality-rewrite-actions')) el('quality-rewrite-actions').hidden = true;
        return true;
    }
    window.ChapterQualityRewrite = { start, adopt, again, resume, prepareOtherOperation, unsavedFields, blockIfUnsaved };
})();
