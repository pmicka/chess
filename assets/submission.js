/* An idempotency identifier is not authentication. Never persist host/CAPTCHA tokens. */
window.ChessSubmission = function (endpoint, storageKey = null) {
  let pending = null;
  let storageError = false;
  if (storageKey) {
    try {
      const saved = sessionStorage.getItem(storageKey);
      if (saved) {
        const value = JSON.parse(saved);
        if (!/^[a-f0-9]{32}$/.test(value.submission_id) ||
            !Number.isInteger(value.game_id) || !Number.isInteger(value.expected_revision) ||
            !/^[a-h][1-8]$/.test(value.from) || !/^[a-h][1-8]$/.test(value.to) ||
            !/^[qrbn]?$/.test(value.promotion)) throw new Error('Invalid saved submission');
        pending = value;
      }
    } catch (_) { storageError = true; }
  }
  function clear() {
    if (storageKey) {
      try { sessionStorage.removeItem(storageKey); }
      catch (_) { storageError = true; }
    }
    pending = null;
  }
  async function post(payload) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(endpoint, {method:'POST', cache:'no-store',
        headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload), signal:controller.signal});
      const json = await response.json(); // Malformed JSON is an unknown outcome, never success.
      if (response.ok && json && json.ok === true) return json;
      const error = new Error(json?.error || 'Move rejected');
      // Proxies can time out after PHP commits. Only our explicit application
      // rejections resolve an attempt; arbitrary 4xx/HTML/JSON errors do not.
      const rejections = ['submission_conflict','submission_invalid','stale_state','turn_mismatch',
        'turn_locked','illegal_move','state_inconsistent','GAME_OVER','AUTH_MISSING','AUTH_INVALID',
        'AUTH_EXPIRED','captcha_missing','captcha_failed','promotion_invalid','missing_fields',
        'server_managed_fields','game_missing'];
      error.definitive = response.status >= 400 && response.status < 500 && rejections.includes(json?.code);
      throw error;
    } finally { clearTimeout(timeout); }
  }
  return {
    get pending() { return pending; },
    async recover(credentials = {}) {
      if (!pending) return null;
      // A missing receipt can race an in-flight request. Keep the same ID for retry.
      try {
        const result = await post({...pending, ...credentials, action:'receipt'});
        clear(); return result;
      } catch (_) { return null; }
    },
    async send(move, credentials = {}) {
      if (storageError) throw new Error('Browser session storage is unavailable. Enable it before submitting.');
      if (!pending) {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        pending = {submission_id:Array.from(bytes, b=>b.toString(16).padStart(2,'0')).join(''),
          game_id:move.game_id, expected_revision:move.expected_revision,
          from:move.from, to:move.to, promotion:move.promotion || ''};
        if (storageKey) {
          try { sessionStorage.setItem(storageKey, JSON.stringify(pending)); }
          catch (_) { pending=null; throw new Error('Cannot save a retry identifier. Enable session storage before submitting.'); }
        }
      }
      try { const result = await post({...pending, ...credentials}); clear(); return result; }
      catch (error) {
        if (error.definitive) clear();
        else throw new Error((error.message && error.name !== 'AbortError' ? error.message + '. ' : '') +
          'Outcome unconfirmed. Retry Submit move or refresh to check; the same move will not be applied twice.');
        throw error;
      }
    }
  };
};
