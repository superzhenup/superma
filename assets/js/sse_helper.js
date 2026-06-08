/**
 * 通用 SSE 流消费器 — 含自动重连/断点继续
 *
 * 用法：
 *   const reader = new SSEReader({
 *     url: 'api/chapter_chat.php?action=chat',
 *     body: { ... },
 *     onDelta: (text) => { accumulated += text; render(); },
 *     onEvent: (event, data) => { ... },
 *     onError: (err, attempt) => { ... },
 *     onDone: (data) => { ... },
 *     maxRetries: 3,
 *     baseDelay: 2000,
 *     // 重试时如何带上上次累计的内容（让 AI 续写）
 *     buildContinuationBody: (origBody, accumulated) => ({
 *       ...origBody,
 *       message: (origBody.message || '') + '\n\n[上次中断]我已经写了：\n' + accumulated.slice(-1000) + '\n\n请直接续写后续部分，不要重复。'
 *     }),
 *   });
 *   await reader.run();
 */
(function() {
  class SSEReader {
    constructor(opts) {
      this.opts = Object.assign({
        url: '',
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
        body: null,
        maxRetries: 3,
        baseDelay: 2000,
        onDelta: () => {},
        onEvent: () => {},
        onError: () => {},
        onDone: () => {},
        onRetry: () => {},
        buildContinuationBody: null,  // 若提供，重试时会带上累计内容
      }, opts);
      this.accumulated = '';
      this.aborted = false;
      this.activeReader = null;
    }

    abort() {
      this.aborted = true;
      if (this.activeReader) {
        try { this.activeReader.cancel(); } catch{}
      }
    }

    /** 判断错误是否值得重试（网络层错误） */
    _isRetryable(err) {
      const msg = (err?.message || '').toLowerCase();
      return (
        msg.includes('failed to fetch') ||
        msg.includes('network') ||
        msg.includes('http2_protocol_error') ||
        msg.includes('http 502') ||
        msg.includes('http 503') ||
        msg.includes('http 504') ||
        msg.includes('connection') ||
        msg.includes('aborted') ||
        err?.name === 'TypeError' // fetch 失败的 TypeError
      );
    }

    async _runOnce(attempt) {
      const opts = this.opts;
      let body = opts.body;
      // 重试时附加续写指令
      if (attempt > 0 && opts.buildContinuationBody && this.accumulated) {
        body = opts.buildContinuationBody(opts.body, this.accumulated);
      }

      const resp = await fetch(opts.url, {
        method: opts.method,
        headers: opts.headers,
        body: body ? (typeof body === 'string' ? body : JSON.stringify(body)) : undefined,
      });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      if (!resp.body) throw new Error('No response body');

      const reader = resp.body.getReader();
      this.activeReader = reader;
      const decoder = new TextDecoder('utf-8');
      let buf = '';
      let lastChunkTime = Date.now();
      const STALL_TIMEOUT_MS = 90000; // 90 秒无任何数据 → 视为卡死

      while (true) {
        if (this.aborted) throw new Error('aborted');

        // 用 Promise.race 检测 stall
        const readPromise = reader.read();
        const stallPromise = new Promise((_, reject) => {
          setTimeout(() => reject(new Error('stall timeout')), STALL_TIMEOUT_MS);
        });

        let result;
        try {
          result = await Promise.race([readPromise, stallPromise]);
        } catch (e) {
          if (e.message === 'stall timeout') {
            // 主动断开，让外层重试
            try { reader.cancel(); } catch{}
            throw new Error('网络空闲超时（network stalled）');
          }
          throw e;
        }

        const { value, done } = result;
        if (done) break;
        lastChunkTime = Date.now();
        buf += decoder.decode(value, { stream: true });

        const blocks = buf.split('\n\n');
        buf = blocks.pop();
        for (const b of blocks) {
          if (!b.trim()) continue;
          // 跳过注释行（: ping）
          if (b.startsWith(':')) continue;

          let evt = 'message', payload = '';
          for (const line of b.split('\n')) {
            if (line.startsWith('event:')) evt = line.slice(6).trim();
            else if (line.startsWith('data:')) payload += line.slice(5).trim();
          }
          if (!payload) continue;
          let d;
          try { d = JSON.parse(payload); } catch { continue; }

          if (evt === 'delta' && d.text) {
            this.accumulated += d.text;
            opts.onDelta(d.text, this.accumulated);
          } else if (evt === 'done') {
            opts.onDone(d);
          } else if (evt === 'error') {
            // 服务端业务错误，不重试
            opts.onEvent(evt, d);
            return { ok: false, businessError: true };
          } else {
            opts.onEvent(evt, d);
          }
        }
      }
      this.activeReader = null;
      return { ok: true };
    }

    async run() {
      const opts = this.opts;
      let lastErr = null;
      for (let attempt = 0; attempt <= opts.maxRetries; attempt++) {
        if (this.aborted) return { ok: false, aborted: true };

        if (attempt > 0) {
          const delay = Math.min(opts.baseDelay * Math.pow(2, attempt - 1), 30000);
          opts.onRetry(attempt, delay, lastErr);
          await new Promise(r => setTimeout(r, delay));
          if (this.aborted) return { ok: false, aborted: true };
        }

        try {
          const r = await this._runOnce(attempt);
          if (r.ok) return { ok: true, accumulated: this.accumulated, attempts: attempt + 1 };
          if (r.businessError) return { ok: false, businessError: true };
        } catch (e) {
          lastErr = e;
          opts.onError(e, attempt);
          if (this.aborted) return { ok: false, aborted: true };
          if (!this._isRetryable(e)) {
            // 不可重试错误，停止
            return { ok: false, error: e };
          }
          // 继续 retry
        }
      }
      return { ok: false, error: lastErr, exhausted: true };
    }
  }

  window.SSEReader = SSEReader;
})();
