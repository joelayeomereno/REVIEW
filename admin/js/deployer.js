/* ============================================================
   WP FOLDER DEPLOYER v3 — OMNI QUEUE ENGINE
   Multi-plugin parallel deploy · Virtual file tree · Conflict guard
   Delta diff · ZIP/Batch adaptive · 16× concurrency per job
   ============================================================ */
( function () {
'use strict';

/* ── Runtime config (injected by wp_localize_script) ─────── */
const CFG = window.WPFD || {};
const REST        = CFG.restUrl   || '';
const NONCE       = CFG.nonce     || '';
const WP_NONCE    = CFG.wpNonce   || '';
const HAS_ZIP     = !!CFG.hasZip;
const SRV_MAX     = CFG.serverMaxUpload || 64 * 1024 * 1024;
const JSZIP_URL   = CFG.jsZipUrl  || '';

/* ── Engine constants ────────────────────────────────────── */
const BATCH_SIZE        = 80;          // files per batch request
const CHUNK_SIZE        = 2 * 1024 * 1024; // 2 MB per chunk for large files
const LARGE_FILE        = 8 * 1024 * 1024; // > 8 MB → chunked
const MAX_WORKERS       = 16;          // parallel XHR per job
const CHUNK_RETRIES     = 3;           // per-request retry limit
const VIRTUAL_PAGE      = 200;         // max rows rendered in tree at once
const PARALLEL_JOBS     = 3;           // max simultaneous plugin deploys

/* ── Blocked extensions ──────────────────────────────────── */
const BLOCKED = new Set(['exe','sh','bash','bat','cmd','com','msi','dll','so','phar','cgi','pl','py','rb','go','vbs','wsf','ps1','jar','class']);

/* ── Ext → icon map ──────────────────────────────────────── */
const ICONS = {
  php:'🐘', js:'🟨', jsx:'⚛', ts:'🔷', tsx:'⚛', css:'🎨', scss:'🎨', sass:'🎨',
  html:'🌐', htm:'🌐', json:'📋', xml:'📋', md:'📝', txt:'📄', svg:'🎭',
  png:'🖼', jpg:'🖼', jpeg:'🖼', gif:'🖼', webp:'🖼', ico:'🖼',
  woff:'🔤', woff2:'🔤', ttf:'🔤', eot:'🔤',
  pot:'🌍', po:'🌍', mo:'🌍', map:'🗺', lock:'🔒', sql:'🗃',
};

function ext_icon(f) { return ICONS[(f.split('.').pop()||'').toLowerCase()] || '📄'; }
function is_blocked(f) { return BLOCKED.has((f.split('.').pop()||'').toLowerCase()); }
function uid() { return Date.now().toString(36) + Math.random().toString(36).slice(2,7); }
function esc_html(str) {
  const div = document.createElement('div');
  div.textContent = String(str ?? '');
  return div.innerHTML;
}
function fmt_b(n) {
  if (n < 1024) return n + 'B';
  if (n < 1048576) return (n/1024).toFixed(1) + 'KB';
  return (n/1048576).toFixed(2) + 'MB';
}
function fmt_ms(ms) {
  if (ms < 1000) return ms + 'ms';
  if (ms < 60000) return (ms/1000).toFixed(1) + 's';
  return Math.floor(ms/60000) + 'm ' + Math.round((ms%60000)/1000) + 's';
}
function now_ts() { return new Date().toLocaleTimeString('en',{hour12:false}); }
function slug_from_path(p) { return (p||'').split('/')[0] || ''; }

/* ── JSZip lazy loader ───────────────────────────────────── */
let _jszipP = null;
function load_jszip() {
  if (_jszipP) return _jszipP;
  if (!JSZIP_URL) return (_jszipP = Promise.resolve(false));
  _jszipP = new Promise(res => {
    const s = document.createElement('script');
    s.src = JSZIP_URL;
    s.onload = () => res(true);
    s.onerror = () => res(false);
    document.head.appendChild(s);
  });
  return _jszipP;
}
if (HAS_ZIP && JSZIP_URL) load_jszip();

/* ── XHR wrapper ─────────────────────────────────────────── */
function xhr_req(method, path, body, attempt = 1) {
  return new Promise((resolve, reject) => {
    const x = new XMLHttpRequest();
    x.open(method, REST + path);
    x.setRequestHeader('X-WPFD-Nonce', NONCE);
    x.setRequestHeader('X-WP-Nonce', WP_NONCE);
    x.onload = () => {
      try {
        const d = JSON.parse(x.responseText);
        x.status < 300 ? resolve(d) : reject(d);
      } catch { reject({message:'Bad JSON'}); }
    };
    x.onerror = () => reject({message:'Network error'});
    body instanceof FormData ? x.send(body)
      : body ? (x.setRequestHeader('Content-Type','application/json'), x.send(JSON.stringify(body)))
      : x.send();
  });
}

async function api(method, path, body, retry=CHUNK_RETRIES) {
  try { return await xhr_req(method, path, body); }
  catch(e) {
    if (retry > 1) { await delay(120*(CHUNK_RETRIES-retry+1)); return api(method,path,body,retry-1); }
    throw e;
  }
}

function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

/* ── File reading helpers (drag-drop recursive) ──────────── */
async function read_dropped(dataTransfer) {
  const items = dataTransfer.items;
  if (!items || !items[0]?.webkitGetAsEntry) {
    return Array.from(dataTransfer.files);
  }
  /* CRITICAL: DataTransferItemList is zeroed by the browser on the first microtask
     tick after the drop event. Extract ALL FileSystemEntry refs SYNCHRONOUSLY
     (before any await) — otherwise items[1+] are null on multi-folder drops. */
  const entries = [];
  for (let i = 0; i < items.length; i++) {
    const entry = items[i].webkitGetAsEntry?.();
    if (entry) entries.push(entry);
  }
  const files = [];
  async function read_entry(entry, prefix) {
    if (entry.isFile) {
      await new Promise(res => entry.file(f => { f._rel = prefix + f.name; files.push(f); res(); }, res));
    } else if (entry.isDirectory) {
      const reader = entry.createReader();
      let batch;
      const all = [];
      do { batch = await new Promise((res,rej) => reader.readEntries(res,rej)); all.push(...batch); } while(batch.length);
      for (const e of all) await read_entry(e, prefix + entry.name + '/');
    }
  }
  for (const entry of entries) await read_entry(entry, '');
  return files;
}

function rel_path(f) { return f._rel || f.webkitRelativePath || f.name; }

/* ── Build virtual tree structure ────────────────────────── */
function build_flat_tree(files) {
  const rows = [];
  const dirs = new Set();
  for (const f of files) {
    const parts = rel_path(f).split('/');
    for (let i=1; i<parts.length; i++) {
      const d = parts.slice(0,i).join('/');
      if (!dirs.has(d)) { dirs.add(d); rows.push({isDir:true, depth:i-1, name:parts[i-1], path:d, size:0, file:null, blocked:false}); }
    }
    rows.push({isDir:false, depth:parts.length-1, name:parts[parts.length-1], path:rel_path(f), size:f.size, file:f, blocked:is_blocked(f.name)});
  }
  return rows;
}

/* ── SHA-256 hash (for delta) ────────────────────────────── */
async function sha256(file) {
  const buf = await file.arrayBuffer();
  const hash = await crypto.subtle.digest('SHA-256', buf);
  return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2,'0')).join('');
}

/* ════════════════════════════════════════════════════════════
   DEPLOY JOB — one plugin slug
   ════════════════════════════════════════════════════════════ */
class DeployJob {
  constructor(slug, files, opts, onUpdate) {
    this.id        = uid();
    this.slug      = slug;
    this.files     = files;
    this.opts      = opts;           // {activate, backup, delta}
    this.onUpdate  = onUpdate;       // callback → queue re-renders

    /* stats */
    this.total     = 0;
    this.written   = 0;
    this.failed    = 0;
    this.skipped   = 0;
    this.bytesTotal   = files.reduce((s,f) => s+f.size, 0);
    this.bytesWritten = 0;
    this.startMs   = null;
    this.endMs     = null;
    this.mode      = 'batch';

    /* state: queued | running | done | failed */
    this.status    = 'queued';
    this.expanded  = false;
    this.log       = [];
    this.backupPath = '';
    this.sessionId = uid();
    this.aborted   = false;
  }

  pct() {
    if (!this.total) return 0;
    return Math.min(100, Math.round((this.written+this.failed+this.skipped)/this.total*100));
  }

  elapsed() { return this.startMs ? (this.endMs||Date.now()) - this.startMs : 0; }

  speed() {
    const e = this.elapsed();
    return e > 0 ? this.bytesWritten / e * 1000 : 0;
  }

  eta() {
    const spd = this.speed();
    if (!spd) return null;
    const rem = this.bytesTotal - this.bytesWritten;
    return rem / spd * 1000;
  }

  log_line(type, path, msg) {
    this.log.push({type, path, msg, ts: now_ts()});
    if (this.log.length > 600) this.log.shift();
    this.onUpdate(this);
  }

  async run() {
    this.status  = 'running';
    this.startMs = Date.now();
    this.onUpdate(this);

    const allowed = this.files.filter(f => !is_blocked(f.name));
    if (!allowed.length) {
      this.status = 'failed';
      this.log_line('err', this.slug, 'No allowed files');
      return;
    }

    /* Step 1: prepare (backup upfront, eliminates race) */
    this.log_line('info', this.slug, '⚙ Preparing session…');
    try {
      const prep = await api('POST', '/prepare-deploy', {plugin_slug:this.slug, session_id:this.sessionId});
      this.backupPath = prep.backup_path || '';
      if (this.backupPath) this.log_line('info', this.slug, '✅ Backup created');
    } catch(e) {
      this.log_line('info', this.slug, '⚠ Prepare skipped: ' + (e.message||''));
    }

    /* Step 2: delta diff (skip unchanged files) */
    let toUpload = allowed;
    if (this.opts.delta) {
      this.log_line('info', this.slug, '🔍 Delta check…');
      try {
        const {manifest} = await api('GET', '/manifest?slug='+encodeURIComponent(this.slug), null);
        if (manifest && Object.keys(manifest).length) {
          const hashes = await Promise.all(allowed.map(f => sha256(f)));
          toUpload = allowed.filter((f,i) => {
            const serverHash = manifest[rel_path(f)];
            if (serverHash && serverHash === hashes[i]) { this.skipped++; return false; }
            return true;
          });
          if (this.skipped) this.log_line('info', this.slug, `⚡ ${this.skipped} files skipped (unchanged)`);
        }
      } catch { /* proceed without delta */ }
    }

    this.total = toUpload.length;
    this.onUpdate(this);

    if (!toUpload.length) {
      this.status = 'done';
      this.endMs  = Date.now();
      this.log_line('info', this.slug, 'No changes to upload');
      this.onUpdate(this);
      return;
    }

    /* Step 3: mode selection — ZIP or Batch */
    const totalSize = toUpload.reduce((s,f)=>s+f.size, 0);
    const hasLarge  = toUpload.some(f => f.size > LARGE_FILE);

    if (HAS_ZIP && !hasLarge && totalSize < SRV_MAX) {
      const zipReady = await load_jszip();
      if (zipReady && typeof JSZip !== 'undefined') {
        this.mode = 'zip';
        this.log_line('info', this.slug, `📦 ZIP mode — ${toUpload.length} files (${fmt_b(totalSize)})`);
        try {
          const zip = new JSZip();
          for (const f of toUpload) zip.file(rel_path(f), f);
          const blob = await zip.generateAsync(
            {type:'blob', compression:'DEFLATE', compressionOptions:{level:6}},
            meta => { this.onUpdate(this); }
          );
          this.log_line('info', this.slug, `📦 Compressed: ${fmt_b(blob.size)}`);
          const fd = new FormData();
          fd.append('zip', blob, 'deploy.zip');
          fd.append('plugin_slug', this.slug);
          fd.append('session_id', this.sessionId);
          const res = await api('POST', '/upload-zip', fd);
          if (res.success) {
            this.written      = res.written || toUpload.length;
            this.failed       = res.failed  || 0;
            this.bytesWritten = totalSize;
            (res.errors||[]).forEach(e => this.log_line('err','',e));
          } else throw new Error(res.message||'ZIP upload failed');
        } catch(e) {
          this.log_line('info', this.slug, '⚠ ZIP failed → batch: '+e.message);
          this.mode = 'batch';
        }
      }
    }

    /* Step 4: Batch mode (or fallback from ZIP) */
    if (this.mode === 'batch') {
      const concurrency  = this._concurrency(toUpload);
      const totalBatches = Math.ceil(toUpload.length / BATCH_SIZE);
      this.log_line('info', this.slug, `⚡ BATCH × ${concurrency} workers — ${totalBatches} batches`);

      let batchIdx = 0;
      const run_batch = async () => {
        while (!this.aborted && batchIdx * BATCH_SIZE < toUpload.length) {
          const bi    = batchIdx++;
          const start = bi * BATCH_SIZE;
          const batch = toUpload.slice(start, start + BATCH_SIZE);
          try {
            const fd = new FormData();
            fd.append('plugin_slug', this.slug);
            fd.append('session_id', this.sessionId);
            fd.append('count', String(batch.length));
            batch.forEach((f,i) => { fd.append('file_'+i, f); fd.append('path_'+i, rel_path(f)); });
            const res = await api('POST', '/upload-batch', fd);
            if (res.results) {
              res.results.forEach((r,ri) => {
                const f = batch[ri];
                if (r.success) {
                  this.written++;
                  this.bytesWritten += f?.size||0;
                  this.log_line('ok', rel_path(f), '✓');
                } else {
                  this.failed++;
                  this.log_line('err', rel_path(f||{}), r.message||'');
                }
              });
            }
          } catch(e) {
            batch.forEach(f => { this.failed++; this.log_line('err', rel_path(f), e.message||'Batch failed'); });
          }
          this.onUpdate(this);
        }
      };
      await Promise.all(Array.from({length:concurrency}, run_batch));
    }

    /* Step 5: finalise */
    if (this.aborted) {
      this.status = 'failed';
      this.endMs  = Date.now();
      this.log_line('err', this.slug, 'Aborted');
      this.onUpdate(this);
      return;
    }

    this.log_line('info', this.slug, 'Finalising…');
    try {
      await api('POST', '/finalise', {
        plugin_slug: this.slug,
        file_count:  this.written,
        activate:    this.opts.activate,
        session_id:  this.sessionId,
        version:     '',
        deploy_mode: this.mode,
        skipped:     this.skipped,
        elapsed_ms:  this.elapsed(),
      });
      this.status = 'done';
      this.endMs  = Date.now();
      this.log_line('info', this.slug, `✅ Done via ${this.mode.toUpperCase()} in ${fmt_ms(this.elapsed())}`);
    } catch(e) {
      this.status = 'failed';
      this.endMs  = Date.now();
      this.log_line('err', this.slug, 'Finalise failed: '+e.message);
    }
    this.onUpdate(this);
  }

  _concurrency(files) {
    const avg = files.length ? files.reduce((s,f)=>s+f.size,0)/files.length : 0;
    if (avg < 30*1024)  return MAX_WORKERS;
    if (avg < 300*1024) return 10;
    return 6;
  }
}

/* ════════════════════════════════════════════════════════════
   DEPLOY QUEUE — orchestrates N parallel jobs
   ════════════════════════════════════════════════════════════ */
class DeployQueue {
  constructor() {
    this.jobs    = [];      // all DeployJob instances
    this.running = 0;
    this.onRender = null;   // set by UI
  }

  add(slug, files, opts) {
    /* Conflict check: slug already in queue */
    if (this.jobs.some(j => j.slug === slug && j.status !== 'done' && j.status !== 'failed')) {
      toast('warning', 'Conflict', `"${slug}" is already in the queue.`);
      return false;
    }
    const job = new DeployJob(slug, files, opts, () => this._update());
    this.jobs.unshift(job);
    this._update();
    this._tick();
    return true;
  }

  _tick() {
    const pending = this.jobs.filter(j => j.status === 'queued');
    while (this.running < PARALLEL_JOBS && pending.length) {
      const j = pending.shift();
      this.running++;
      j.run().then(() => { this.running--; this._tick(); });
    }
  }

  _update() {
    if (this.onRender) this.onRender();
  }

  get totalFiles()   { return this.jobs.reduce((s,j)=>s+j.total,0); }
  get totalWritten() { return this.jobs.reduce((s,j)=>s+j.written,0); }
  get totalFailed()  { return this.jobs.reduce((s,j)=>s+j.failed,0); }
  get totalSkipped() { return this.jobs.reduce((s,j)=>s+j.skipped,0); }
  get totalBytes()   { return this.jobs.reduce((s,j)=>s+j.bytesTotal,0); }
  get bytesWritten() { return this.jobs.reduce((s,j)=>s+j.bytesWritten,0); }
  get allDone()      { return this.jobs.length > 0 && this.jobs.every(j=>j.status==='done'||j.status==='failed'); }
  get hasActive()    { return this.jobs.some(j=>j.status==='running'||j.status==='queued'); }

  pct() {
    const t = this.totalFiles;
    if (!t) return 0;
    return Math.min(100, Math.round((this.totalWritten+this.totalFailed+this.totalSkipped)/t*100));
  }

  speed() {
    let total = 0;
    this.jobs.filter(j=>j.status==='running').forEach(j=>total+=j.speed());
    return total;
  }
}

/* ════════════════════════════════════════════════════════════
   GLOBAL APP STATE
   ════════════════════════════════════════════════════════════ */
const queue   = new DeployQueue();
const appState = {
  tab:          'deploy',
  opts:         {activate:false, backup:true, delta:false},
  pending:      [],        // [{slug, files, flat}] — staged, not yet queued
  trayExpanded: new Set(), // A2: slugs whose file-tree card is expanded
  trayChecked:  new Set(), // A5: slugs checked for Deploy Selected
  search:       '',
  plugins:      [],
  history:      [],
  backups:      [],
  deployDone:   false,
  browser:      { root: 'plugins', path: '', roots: {}, entries: [], loading: false, breadcrumbs: [], deployHereSlug: null, search: '', sort: 'name', checked: new Set() },
  downloadQueue:[], // [{id, name, status:'pending'|'downloading'|'done'|'error', url:''}]
  settings:     { backup_retention: 5 },
};

/* ════════════════════════════════════════════════════════════
   TOAST — enhanced with auto-dismiss progress
   ════════════════════════════════════════════════════════════ */
function toast(type, title, msg) {
  const icons = {success:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16A34A" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>', error:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>', info:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>', warning:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'};
  const wrap  = document.getElementById('wpfd-toasts');
  if (!wrap) return;
  const el = document.createElement('div');
  el.className = `wpfd-toast ${type}`;
  el.innerHTML = `<span class="toast-ico">${icons[type]||icons.info}</span><div class="toast-body"><div class="toast-title">${esc_html(title)}</div>${msg?`<div class="toast-msg">${esc_html(msg)}</div>`:''}</div><button class="toast-close" onclick="this.closest('.wpfd-toast').classList.add('out');setTimeout(()=>this.closest('.wpfd-toast')?.remove(),200)">×</button><div class="toast-progress"><div class="toast-progress-bar"></div></div>`;
  wrap.prepend(el);
  setTimeout(()=>{ el.classList.add('out'); setTimeout(()=>el.remove(),200); }, 4800);
}

/* ════════════════════════════════════════════════════════════
   RENDER ENGINE
   ════════════════════════════════════════════════════════════ */

/* ── Shell (one-time) ─────────────────────────────────────── */
function render_shell() {
  const root = document.getElementById('wpfd-root');
  root.innerHTML = `
<div class="wpfd-accent-bar"></div>
<div class="wpfd-shell">
  <header class="wpfd-topbar">
    <div class="wpfd-logo-zone">
      <div class="wpfd-logo">
        <span class="wpfd-logo-mark">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </span>
        <span class="wpfd-logo-text">Folder Deployer</span>
        <span class="wpfd-logo-badge">v6.0</span>
      </div>
    </div>
    <div class="wpfd-topbar-body">
      <div class="wpfd-server-chips" id="wpfd-srv-info"></div>
      <div class="wpfd-topbar-right">
        <div class="wpfd-status-pill" id="wpfd-status-pill">
          <span class="wpfd-pulse-dot"></span>
          <span>Connected</span>
        </div>
      </div>
    </div>
  </header>

  <nav class="wpfd-sidebar" id="wpfd-sidebar">
    <div class="wpfd-nav-section">
      <span class="wpfd-nav-label">Deploy</span>
      <div class="wpfd-nav-item active" data-tab="deploy">
        <span class="wpfd-nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg></span>
        <span class="wpfd-nav-text">Deploy</span>
        <span class="wpfd-nav-badge" id="nav-count-deploy">0</span>
      </div>
      <div class="wpfd-nav-item" data-tab="queue">
        <span class="wpfd-nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></span>
        <span class="wpfd-nav-text">Queue</span>
        <span class="wpfd-nav-badge" id="nav-count-queue">0</span>
      </div>
      <div class="wpfd-nav-item" data-tab="downloads">
        <span class="wpfd-nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></span>
        <span class="wpfd-nav-text">Downloads</span>
        <span class="wpfd-nav-badge" id="nav-count-downloads">0</span>
      </div>
    </div>
    <div class="wpfd-nav-sep"></div>
    <div class="wpfd-nav-section">
      <span class="wpfd-nav-label">Manage</span>
      <div class="wpfd-nav-item" data-tab="plugins">
        <span class="wpfd-nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg></span>
        <span class="wpfd-nav-text">Plugins</span>
        <span class="wpfd-nav-badge" id="nav-count-plugins">—</span>
      </div>
      <div class="wpfd-nav-item" data-tab="history">
        <span class="wpfd-nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
        <span class="wpfd-nav-text">History</span>
        <span class="wpfd-nav-badge" id="nav-count-history">—</span>
      </div>
      <div class="wpfd-nav-item" data-tab="browser">
        <span class="wpfd-nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg></span>
        <span class="wpfd-nav-text">File Browser</span>
      </div>
      <div class="wpfd-nav-item" data-tab="backups">
        <span class="wpfd-nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span>
        <span class="wpfd-nav-text">Backups</span>
        <span class="wpfd-nav-badge" id="nav-count-backups">—</span>
      </div>
    </div>
    <div class="wpfd-nav-sep"></div>
    <div class="wpfd-nav-section">
      <span class="wpfd-nav-label">System</span>
      <div class="wpfd-nav-item" data-tab="settings">
        <span class="wpfd-nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
        <span class="wpfd-nav-text">Settings</span>
      </div>
    </div>
    <div class="wpfd-sidebar-footer" id="wpfd-sidebar-footer"></div>
  </nav>

  <main class="wpfd-main">
    <div class="wpfd-tab active" id="tab-deploy"></div>
    <div class="wpfd-tab" id="tab-queue"></div>
    <div class="wpfd-tab" id="tab-downloads"></div>
    <div class="wpfd-tab" id="tab-plugins"></div>
    <div class="wpfd-tab" id="tab-history"></div>
    <div class="wpfd-tab" id="tab-browser"></div>
    <div class="wpfd-tab" id="tab-backups"></div>
    <div class="wpfd-tab" id="tab-settings"></div>
  </main>
</div>
<div class="wpfd-toasts" id="wpfd-toasts"></div>
`;

  /* Nav click */
  document.querySelectorAll('.wpfd-nav-item').forEach(el => {
    el.addEventListener('click', () => switch_tab(el.dataset.tab));
  });

  /* Server chips */
  const si = document.getElementById('wpfd-srv-info');
  if (si) {
    const mb = (n) => (n/1024/1024).toFixed(0)+'MB';
    si.innerHTML = `<span class="wpfd-chip">Upload limit <strong>${mb(SRV_MAX)}</strong></span><span class="wpfd-chip">ZIP <strong>${HAS_ZIP?'enabled':'off'}</strong></span>`;
  }

  /* Queue callback */
  queue.onRender = render_queue_tab;

  render_deploy_tab();
  render_sidebar_footer();
}

/* ── Switch tab ───────────────────────────────────────────── */
function switch_tab(tab) {
  appState.tab = tab;
  document.querySelectorAll('.wpfd-tab').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.wpfd-nav-item').forEach(el => el.classList.toggle('active', el.dataset.tab===tab));
  const el = document.getElementById('tab-'+tab);
  if (el) el.classList.add('active');
  if (tab==='plugins') load_plugins();
  if (tab==='history') load_history();
  if (tab==='backups') load_backups();
  if (tab==='browser') load_browser();
  if (tab==='queue')   render_queue_tab();
  if (tab==='downloads') render_downloads_tab();
  if (tab==='settings') load_settings();
}

/* ── Sidebar footer ───────────────────────────────────────── */
function render_sidebar_footer() {
  const el = document.getElementById('wpfd-sidebar-footer');
  if (!el) return;
  const active = queue.jobs.filter(j=>j.status==='running').length;
  el.innerHTML = `
    <div class="wpfd-sf-row">
      <span>Active jobs</span><strong>${active}</strong>
    </div>
    <div class="wpfd-sf-row">
      <span>Total deployed</span><strong>${queue.totalWritten}</strong>
    </div>
  `;
  /* update nav counts */
  const dc = document.getElementById('nav-count-deploy');
  const qc = document.getElementById('nav-count-queue');
  if (dc) dc.textContent = appState.pending.length || '0';
  if (qc) {
    const running = queue.jobs.filter(j=>j.status!=='done'&&j.status!=='failed').length;
    qc.textContent = running || queue.jobs.length || '0';
  }
}

/* ════════════════════════════════════════════════════════════
   DEPLOY TAB — Section A God Mode: two-panel + staging tray
   ════════════════════════════════════════════════════════════ */
function render_deploy_tab() {
  const tab = document.getElementById('tab-deploy');
  if (!tab) return;

  if (appState.deployDone && queue.allDone && queue.jobs.length) {
    render_deploy_success(tab);
    return;
  }

  tab.innerHTML = `
<div class="wpfd-page-hd">
  <div>
    <h1 class="wpfd-page-title">Deploy Plugins</h1>
    <p class="wpfd-page-sub">Push plugin folders directly to <code>/wp-content/plugins/</code></p>
  </div>
</div>

<div class="wpfd-deploy-panels">

  <!-- ── LEFT: Drop zone + Options ── -->
  <div class="wpfd-drop-panel">
    <div class="wpfd-drop-arena" id="wpfd-drop-arena">
      <div class="wpfd-drop-inner">
        <div class="wpfd-drop-icon-wrap"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></div>
        <div class="wpfd-drop-title">Drop plugin folders here</div>
        <p class="wpfd-drop-sub">Each top-level folder becomes a card in the staging tray →</p>
        <div class="wpfd-drop-cards">
          <button class="wpfd-drop-card" id="btn-pick-folder">
            <div class="wpfd-drop-card-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
            <div class="wpfd-drop-card-label">Choose Folder</div>
            <div class="wpfd-drop-card-desc">Entire plugin directory</div>
          </button>
          <button class="wpfd-drop-card" id="btn-pick-files">
            <div class="wpfd-drop-card-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="wpfd-drop-card-label">Choose Files</div>
            <div class="wpfd-drop-card-desc">Individual plugin files</div>
          </button>
        </div>
        <!-- A4: inputs stay in DOM; value cleared after each pick so same folder can be re-added -->
        <input type="file" id="wpfd-folder-input" webkitdirectory multiple style="display:none">
        <input type="file" id="wpfd-files-input" multiple style="display:none">
      </div>
    </div>

    <div class="wpfd-options-strip" style="margin-top:14px">
      <label class="wpfd-toggle">
        <input type="checkbox" id="opt-activate" ${appState.opts.activate?'checked':''}>
        <span class="wpfd-toggle-track"><span class="wpfd-toggle-thumb"></span></span>
        <span>Activate after deploy</span>
      </label>
      <label class="wpfd-toggle">
        <input type="checkbox" id="opt-backup" ${appState.opts.backup?'checked':''}>
        <span class="wpfd-toggle-track"><span class="wpfd-toggle-thumb"></span></span>
        <span>Auto-backup existing</span>
      </label>
      <label class="wpfd-toggle">
        <input type="checkbox" id="opt-delta" ${appState.opts.delta?'checked':''}>
        <span class="wpfd-toggle-track"><span class="wpfd-toggle-thumb"></span></span>
        <span>Skip unchanged (delta)</span>
      </label>
    </div>

    <div class="wpfd-status-bar" style="margin-top:12px">
      <div class="wpfd-status-item"><span class="wpfd-status-dot wpfd-dot-blue"></span><span class="wpfd-status-val" id="stat-staged">${appState.pending.length}</span> staged</div>
      <div class="wpfd-status-item"><span class="wpfd-status-dot wpfd-dot-amber"></span><span class="wpfd-status-val">${queue.jobs.filter(j=>j.status==='running').length}</span> running</div>
      <div class="wpfd-status-item"><span class="wpfd-status-dot wpfd-dot-green"></span><span class="wpfd-status-val">${queue.totalWritten}</span> written</div>
      <div class="wpfd-status-item"><span class="wpfd-status-dot wpfd-dot-red"></span><span class="wpfd-status-val">${queue.totalFailed}</span> failed</div>
    </div>
  </div>

  <!-- ── RIGHT: Staging Tray (A1) ── -->
  <div class="wpfd-tray-panel">
    <div class="wpfd-tray-hd">
      <div class="wpfd-tray-hd-left">
        <!-- A5: master checkbox -->
        <label class="wpfd-master-chk-wrap" title="Select / deselect all">
          <input type="checkbox" id="tray-check-all" class="wpfd-chk">
          <span class="wpfd-master-chk-label">Staging Tray</span>
        </label>
        <span class="wpfd-tray-count-pill" id="tray-count-pill">—</span>
      </div>
      <div class="wpfd-tray-hd-right">
        <button class="wpfd-btn wpfd-btn-sm wpfd-btn-secondary" id="btn-deploy-selected" disabled>Deploy Selected (0)</button>
        <button class="wpfd-btn wpfd-btn-sm wpfd-btn-primary" id="btn-deploy-all" disabled>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
          Deploy All (0)
        </button>
      </div>
    </div>
    <div id="wpfd-staging-tray" class="wpfd-tray-body"></div>
  </div>

</div>
`;

  bind_deploy_events(tab);
  render_staging_tray();
}

function bind_deploy_events(tab) {
  const folderInput = document.getElementById('wpfd-folder-input');
  const filesInput  = document.getElementById('wpfd-files-input');

  document.getElementById('btn-pick-folder')?.addEventListener('click', () => folderInput.click());
  document.getElementById('btn-pick-files')?.addEventListener('click',  () => filesInput.click());

  /* A4: clear value after each pick so the same folder can be re-selected without losing the tray */
  folderInput?.addEventListener('change', e => {
    ingest_files(Array.from(e.target.files));
    e.target.value = '';
  });
  filesInput?.addEventListener('change', e => {
    ingest_files(Array.from(e.target.files), true);
    e.target.value = '';
  });

  document.getElementById('opt-activate')?.addEventListener('change', e => { appState.opts.activate = e.target.checked; });
  document.getElementById('opt-backup')?.addEventListener('change',   e => { appState.opts.backup   = e.target.checked; });
  document.getElementById('opt-delta')?.addEventListener('change',    e => { appState.opts.delta    = e.target.checked; });

  document.getElementById('btn-deploy-all')?.addEventListener('click',      deploy_all_pending);
  document.getElementById('btn-deploy-selected')?.addEventListener('click', deploy_selected_pending);

  /* A5: master checkbox wires into trayChecked */
  document.getElementById('tray-check-all')?.addEventListener('change', e => {
    if (e.target.checked) {
      appState.pending.forEach(p => appState.trayChecked.add(p.slug));
    } else {
      appState.trayChecked.clear();
    }
    render_staging_tray();
  });

  /* A3: drop zone — depth counter prevents drag-over flicker on child-element leave */
  const arena = document.getElementById('wpfd-drop-arena');
  let _dragDepth = 0;
  arena?.addEventListener('dragenter',  e => { e.preventDefault(); e.stopPropagation(); if (++_dragDepth === 1) arena.classList.add('drag-over'); });
  arena?.addEventListener('dragover',   e => { e.preventDefault(); e.stopPropagation(); });
  arena?.addEventListener('dragleave',  e => { e.stopPropagation(); if (--_dragDepth <= 0) { _dragDepth = 0; arena.classList.remove('drag-over'); } });
  arena?.addEventListener('drop', async e => {
    e.preventDefault(); e.stopPropagation(); _dragDepth = 0; arena.classList.remove('drag-over');
    try {
      const files = await read_dropped(e.dataTransfer);
      if (files.length) ingest_files(files);
      else toast('info', 'No files', 'The dropped items contained no readable files.');
    } catch (err) {
      toast('error', 'Drop failed', err.message || 'Could not read dropped files.');
    }
  });
}

/* Ingest a list of File objects → group by top-level slug → add to staging tray */
function ingest_files(files, forceSlugPrompt = false) {
  if (!files.length) return;

  /* Reject if total size exceeds server limit (with multipart overhead margin) */
  const totalSize = Array.from(files).reduce((s, f) => s + (f.size || 0), 0);
  if (totalSize > SRV_MAX * 1.5) {
    toast('error', 'Payload too large', `Total size ${fmt_b(totalSize)} exceeds server limit ${fmt_b(SRV_MAX)}`);
    return;
  }

  /* Deploy Here override: the first non-prompt slug is renamed to the server target */
  const overrideSlug = appState.browser.deployHereSlug || null;
  if (overrideSlug) appState.browser.deployHereSlug = null; // consume once

  /* A3: group by top-level slug; each group becomes one staging card */
  const groups = {};
  for (const f of files) {
    const p = rel_path(f);
    const slug = slug_from_path(p) || (forceSlugPrompt ? '__prompt__' : 'plugin');
    if (!groups[slug]) groups[slug] = [];
    groups[slug].push(f);
  }

  let firstRealEntry = true;
  for (const [rawSlug, gFiles] of Object.entries(groups)) {
    if (rawSlug === '__prompt__') { show_slug_prompt(gFiles); continue; }
    const slug = (overrideSlug && firstRealEntry) ? overrideSlug : rawSlug;
    firstRealEntry = false;
    const flat = build_flat_tree(gFiles);
    const existing = appState.pending.find(p => p.slug === slug);
    if (existing) {
      /* Merge into existing staging card */
      existing.files.push(...gFiles);
      existing.flat = build_flat_tree(existing.files);
    } else {
      appState.pending.push({slug, files:gFiles, flat});
      appState.trayChecked.add(slug); // A5: auto-check newly staged folders
    }
  }

  render_staging_tray();
  render_sidebar_footer();
}

function show_slug_prompt(files) {
  const backdrop = document.createElement('div');
  backdrop.className = 'wpfd-modal-backdrop';
  backdrop.innerHTML = `
    <div class="wpfd-modal">
      <div class="wpfd-modal-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg> Set Plugin Slug</div>
      <div class="wpfd-modal-body">
        ${files.length} file(s) have no folder context. Enter the plugin slug (folder name) they belong to:
      </div>
      <div style="margin-bottom:18px">
        <label class="wpfd-label">Plugin slug</label>
        <input class="wpfd-input" id="slug-prompt-input" placeholder="my-plugin" autofocus>
      </div>
      <div class="wpfd-modal-actions">
        <button class="wpfd-btn wpfd-btn-secondary" id="slug-prompt-cancel">Cancel</button>
        <button class="wpfd-btn wpfd-btn-primary" id="slug-prompt-ok">Apply</button>
      </div>
    </div>
  `;
  (document.getElementById('wpfd-root') || document.body).appendChild(backdrop);
  backdrop.querySelector('#slug-prompt-input').focus();

  const close = () => backdrop.remove();
  backdrop.querySelector('#slug-prompt-cancel').addEventListener('click', close);
  backdrop.querySelector('#slug-prompt-ok').addEventListener('click', () => {
    const slug = (backdrop.querySelector('#slug-prompt-input').value||'').trim().toLowerCase().replace(/[^a-z0-9_-]/g,'-');
    if (!slug) { toast('error','Missing slug','Enter a valid plugin slug.'); return; }
    files.forEach(f => { if (!f._rel) f._rel = slug+'/'+f.name; });
    ingest_files(files);
    close();
  });
  backdrop.addEventListener('click', e => { if (e.target===backdrop) close(); });
}

/* A2: Rename modal — lets the user set the server target slug independently of the local folder name */
function show_rename_modal(currentSlug) {
  const p = appState.pending.find(x => x.slug === currentSlug);
  if (!p) return;
  const backdrop = document.createElement('div');
  backdrop.className = 'wpfd-modal-backdrop';
  backdrop.innerHTML = `
    <div class="wpfd-modal">
      <div class="wpfd-modal-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Rename Target Slug</div>
      <div class="wpfd-modal-body">Local folder: <code>${esc_html(currentSlug)}</code>. Set the target folder name on the server (this only changes where it deploys, not the local folder):</div>
      <div style="margin-bottom:18px">
        <label class="wpfd-label">Target slug</label>
        <input class="wpfd-input" id="rename-slug-input" value="${esc_html(currentSlug)}" autofocus>
      </div>
      <div class="wpfd-modal-actions">
        <button class="wpfd-btn wpfd-btn-secondary" id="rename-cancel">Cancel</button>
        <button class="wpfd-btn wpfd-btn-primary" id="rename-ok">Apply</button>
      </div>
    </div>
  `;
  (document.getElementById('wpfd-root') || document.body).appendChild(backdrop);
  const input = backdrop.querySelector('#rename-slug-input');
  input.focus(); input.select();
  const close = () => backdrop.remove();
  backdrop.querySelector('#rename-cancel').addEventListener('click', close);
  backdrop.querySelector('#rename-ok').addEventListener('click', () => {
    const newSlug = (input.value || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '-');
    if (!newSlug) { toast('error', 'Invalid slug', 'Enter a valid plugin slug.'); return; }
    if (newSlug !== currentSlug && appState.pending.some(x => x.slug === newSlug)) {
      toast('error', 'Slug taken', `"${newSlug}" is already in the staging tray.`); return;
    }
    /* Update _rel paths on all files so the deploy goes to the new slug */
    p.files.forEach(f => {
      if (f._rel && f._rel.startsWith(currentSlug + '/')) {
        f._rel = newSlug + f._rel.slice(currentSlug.length);
      }
    });
    p.slug = newSlug;
    p.flat = build_flat_tree(p.files);
    /* Migrate tray state keys */
    if (appState.trayChecked.has(currentSlug))  { appState.trayChecked.delete(currentSlug);  appState.trayChecked.add(newSlug);  }
    if (appState.trayExpanded.has(currentSlug)) { appState.trayExpanded.delete(currentSlug); appState.trayExpanded.add(newSlug); }
    render_staging_tray();
    close();
    toast('success', 'Renamed', `Target slug set to "${newSlug}"`);
  });
  backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter')  backdrop.querySelector('#rename-ok').click();
    if (e.key === 'Escape') close();
  });
}

/* ── A1/A2/A5: Render the staging tray ─────────────────────── */
function render_pending_list_compat() {} // no-op shim kept for safety

/* ── A1/A2/A5: Render the staging tray ─────────────────────── */
function render_staging_tray() {
  const tray = document.getElementById('wpfd-staging-tray');
  if (!tray) return;

  /* Update left-panel staged count */
  const statEl = document.getElementById('stat-staged');
  if (statEl) statEl.textContent = appState.pending.length;

  /* A1: live counts across all staged folders */
  const pill = document.getElementById('tray-count-pill');
  if (pill) {
    if (appState.pending.length) {
      const totalFiles = appState.pending.reduce((s,p) => s + p.files.length, 0);
      const totalSize  = appState.pending.reduce((s,p) => s + p.files.reduce((a,f) => a + f.size, 0), 0);
      pill.textContent = `${appState.pending.length} folder${appState.pending.length !== 1 ? 's' : ''} · ${totalFiles} files · ${fmt_b(totalSize)}`;
    } else {
      pill.textContent = '—';
    }
  }

  /* A5: update tray header buttons */
  const checkedCount = appState.pending.filter(p => appState.trayChecked.has(p.slug)).length;
  const dabAll = document.getElementById('btn-deploy-all');
  const dabSel = document.getElementById('btn-deploy-selected');
  if (dabAll) {
    dabAll.disabled = !appState.pending.length;
    dabAll.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg> Deploy All (${appState.pending.length})`;
  }
  if (dabSel) {
    dabSel.disabled = checkedCount === 0;
    dabSel.textContent = `Deploy Selected (${checkedCount})`;
  }

  /* A5: master checkbox tri-state */
  const masterChk = document.getElementById('tray-check-all');
  if (masterChk) {
    const total   = appState.pending.length;
    const checked = appState.pending.filter(p => appState.trayChecked.has(p.slug)).length;
    masterChk.checked       = total > 0 && checked === total;
    masterChk.indeterminate = checked > 0 && checked < total;
  }

  /* Empty state */
  if (!appState.pending.length) {
    tray.innerHTML = `<div class="wpfd-tray-empty">
  <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
  <p>No folders staged</p>
  <span>Drop folders or click Choose Folder to add cards here</span>
</div>`;
    render_sidebar_footer();
    return;
  }

  /* A1/A2: Render one card per staged folder */
  tray.innerHTML = appState.pending.map(p => {
    const expanded  = appState.trayExpanded.has(p.slug);
    const checked   = appState.trayChecked.has(p.slug);
    const totalSize = p.files.reduce((s,f) => s + f.size, 0);
    const allowed   = p.flat.filter(r => !r.isDir && !r.blocked).length;
    const blocked   = p.flat.filter(r => r.blocked).length;
    const slugH     = esc_html(p.slug);
    return `
<div class="wpfd-tray-card${expanded ? ' is-expanded' : ''}" data-slug="${slugH}">
  <div class="wpfd-tray-card-hd">
    <label class="wpfd-chk-wrap" title="Select for Deploy Selected">
      <input type="checkbox" class="tray-item-check wpfd-chk" data-slug="${slugH}" ${checked ? 'checked' : ''}>
    </label>
    <button class="wpfd-tray-toggle" data-toggle-slug="${slugH}" title="${expanded ? 'Collapse' : 'Expand'} file tree">
      <svg class="wpfd-tray-chevron${expanded ? ' rotated' : ''}" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    <span class="wpfd-tray-slug">${slugH}</span>
    <div class="wpfd-tray-badges">
      <span class="wpfd-tray-badge">${p.files.length} files</span>
      <span class="wpfd-tray-badge">${fmt_b(totalSize)}</span>
      ${allowed > 0 ? `<span class="wpfd-tray-badge wpfd-tray-badge-green">${allowed} allowed</span>` : ''}
      ${blocked > 0 ? `<span class="wpfd-tray-badge wpfd-tray-badge-red">${blocked} blocked</span>` : ''}
    </div>
    <div class="wpfd-tray-card-acts">
      <button class="wpfd-btn wpfd-btn-xs wpfd-btn-ghost" data-rename-slug="${slugH}" title="Rename target slug">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      </button>
      <button class="wpfd-btn wpfd-btn-xs wpfd-btn-ghost" data-remove-slug="${slugH}" title="Remove from tray">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
  </div>
  ${expanded ? `
  <div class="wpfd-tray-card-body">
    <div class="wpfd-tree-toolbar">
      <input class="wpfd-tree-search" placeholder="Filter files…" data-tree-search="${slugH}">
      <span class="wpfd-tree-info">${allowed} allowed · <span style="color:var(--red)">${blocked} blocked</span></span>
    </div>
    <div class="wpfd-tree-body" data-tree-body="${slugH}">
      ${render_tree_rows(p.flat, '', 0)}
    </div>
  </div>` : ''}
</div>`;
  }).join('');

  /* ── Bind per-card events ─────────────────────────────────── */

  /* A2: collapse/expand toggle */
  tray.querySelectorAll('[data-toggle-slug]').forEach(btn => {
    btn.addEventListener('click', () => {
      const s = btn.dataset.toggleSlug;
      appState.trayExpanded.has(s) ? appState.trayExpanded.delete(s) : appState.trayExpanded.add(s);
      render_staging_tray();
    });
  });

  /* A5: per-card checkbox */
  tray.querySelectorAll('.tray-item-check').forEach(chk => {
    chk.addEventListener('change', () => {
      const s = chk.dataset.slug;
      chk.checked ? appState.trayChecked.add(s) : appState.trayChecked.delete(s);
      render_staging_tray();
    });
  });

  /* A2: rename button */
  tray.querySelectorAll('[data-rename-slug]').forEach(btn => {
    btn.addEventListener('click', () => show_rename_modal(btn.dataset.renameSlug));
  });

  /* A2: remove button — pulls only that card out of the tray */
  tray.querySelectorAll('[data-remove-slug]').forEach(btn => {
    btn.addEventListener('click', () => {
      const s = btn.dataset.removeSlug;
      const i = appState.pending.findIndex(p => p.slug === s);
      if (i !== -1) {
        appState.pending.splice(i, 1);
        appState.trayChecked.delete(s);
        appState.trayExpanded.delete(s);
        render_staging_tray();
        render_sidebar_footer();
      }
    });
  });

  /* Virtual tree search (uses existing render_tree_rows with 200-row slice) */
  tray.querySelectorAll('[data-tree-search]').forEach(inp => {
    inp.addEventListener('input', () => {
      const s    = inp.dataset.treeSearch;
      const q    = inp.value.toLowerCase();
      const body = tray.querySelector(`[data-tree-body="${s}"]`);
      const p    = appState.pending.find(x => x.slug === s);
      if (body && p) body.innerHTML = render_tree_rows(p.flat, q, 0);
    });
  });

  render_sidebar_footer();
}

function render_tree_rows(flat, filter, startIdx) {
  const q = (filter||'').toLowerCase();
  const visible = flat.filter(r => !q || r.name.toLowerCase().includes(q)).slice(startIdx, startIdx + VIRTUAL_PAGE);
  return visible.map(r => `
    <div class="wpfd-tree-row ${r.isDir?'is-dir':''} ${r.blocked?'blocked':''}" style="padding-left:${14+r.depth*14}px">
      <span class="tr-icon">${r.isDir ? '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>' : ext_icon(r.name)}</span>
      <span class="tr-name">${esc_html(r.name)}</span>
      ${!r.isDir ? `<span class="tr-size">${fmt_b(r.size)}</span>` : ''}
    </div>
  `).join('');
}

function deploy_all_pending() {
  if (!appState.pending.length) return;
  const items = [...appState.pending];
  appState.pending = [];
  appState.trayChecked.clear();
  appState.trayExpanded.clear();
  for (const p of items) dispatch_job(p.slug, p.files);
  render_staging_tray();
  render_sidebar_footer();
  switch_tab('queue');
}

/* A5: deploy only the checked subset */
function deploy_selected_pending() {
  const selected = appState.pending.filter(p => appState.trayChecked.has(p.slug));
  if (!selected.length) return;
  for (const p of selected) {
    const i = appState.pending.findIndex(x => x.slug === p.slug);
    if (i !== -1) appState.pending.splice(i, 1);
    appState.trayChecked.delete(p.slug);
    appState.trayExpanded.delete(p.slug);
    dispatch_job(p.slug, p.files);
  }
  render_staging_tray();
  render_sidebar_footer();
  switch_tab('queue');
}

function dispatch_job(slug, files) {
  const added = queue.add(slug, files, {...appState.opts});
  if (added) {
    appState.deployDone = false;
    toast('info', 'Queued', `"${slug}" added to deploy queue`);
    switch_tab('queue');
    render_queue_tab();
  }
}

function render_deploy_success(tab) {
  const totalWritten = queue.totalWritten;
  const totalMs = queue.jobs.reduce((s,j)=>s+(j.elapsed()||0),0);
  const failedJobs = queue.jobs.filter(j=>j.status==='failed').length;

  tab.innerHTML = `
<div class="wpfd-success-banner">
  <div class="wpfd-success-icon"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
  <h2 class="wpfd-success-title">${queue.jobs.length} plugin${queue.jobs.length!==1?'s':''} deployed!</h2>
  <p class="wpfd-success-sub">
    ${totalWritten} files written &middot; ${queue.jobs.filter(j=>j.status==='done').length} succeeded${failedJobs?` &middot; <span style="color:var(--red)">${failedJobs} failed</span>`:''} &middot; ${fmt_ms(totalMs)} total
  </p>
  <div class="wpfd-success-actions">
    <button class="wpfd-btn wpfd-btn-primary" id="btn-deploy-again"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg> Deploy More</button>
    <button class="wpfd-btn wpfd-btn-secondary" id="btn-view-history"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> View History</button>
    <button class="wpfd-btn wpfd-btn-secondary" id="btn-view-queue"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg> View Queue</button>
  </div>
</div>
`;
  tab.querySelector('#btn-deploy-again').addEventListener('click', () => {
    appState.deployDone = false;
    appState.pending = [];
    render_deploy_tab();
  });
  tab.querySelector('#btn-view-history').addEventListener('click', () => switch_tab('history'));
  tab.querySelector('#btn-view-queue').addEventListener('click',   () => switch_tab('queue'));
}

/* ════════════════════════════════════════════════════════════
   QUEUE TAB — live job cards
   ════════════════════════════════════════════════════════════ */
function render_queue_tab() {
  const tab = document.getElementById('tab-queue');
  if (!tab || appState.tab !== 'queue') {
    render_sidebar_footer();
    /* If all jobs are done, flip to success for next time deploy tab opens */
    if (queue.allDone && queue.jobs.length) {
      appState.deployDone = true;
    }
    return;
  }

  if (!queue.jobs.length) {
    tab.innerHTML = `
<div class="wpfd-page-hd"><div><h1 class="wpfd-page-title">Queue</h1><p class="wpfd-page-sub">No jobs yet — go to Deploy to add plugins.</p></div></div>
<div class="wpfd-empty"><div class="wpfd-empty-icon"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></div><h3 class="wpfd-empty-title">No jobs queued</h3><p>Queue is empty — go to Deploy to add plugins.</p></div>`;
    render_sidebar_footer();
    return;
  }

  /* Global progress bar */
  const pct  = queue.pct();
  const spd  = queue.speed();
  const qphd = queue.hasActive ? `
<div class="wpfd-queue-progress">
  <div class="wpfd-qp-head">
    <span class="wpfd-qp-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg> Queue Progress — ${queue.jobs.filter(j=>j.status==='running').length} active</span>
    <span class="wpfd-qp-pct">${pct}%</span>
  </div>
  <div class="wpfd-qp-track"><div class="wpfd-qp-fill" style="width:${pct}%"></div></div>
  <div class="wpfd-qp-stats">
    <div class="wpfd-qp-stat"><strong>${queue.totalWritten}</strong>written</div>
    <div class="wpfd-qp-stat"><strong>${queue.totalFailed}</strong>failed</div>
    <div class="wpfd-qp-stat"><strong>${queue.totalSkipped}</strong>skipped</div>
    <div class="wpfd-qp-stat"><strong>${fmt_b(spd)}/s</strong>speed</div>
    <div class="wpfd-qp-stat"><strong>${queue.jobs.filter(j=>j.status==='done'||j.status==='failed').length}/${queue.jobs.length}</strong>complete</div>
  </div>
</div>` : '';

  tab.innerHTML = `
<div class="wpfd-page-hd">
  <div><h1 class="wpfd-page-title">Queue</h1>
  <p class="wpfd-page-sub">${queue.jobs.length} job${queue.jobs.length!==1?'s':''} · ${queue.jobs.filter(j=>j.status==='running').length} running · ${queue.jobs.filter(j=>j.status==='queued').length} waiting</p></div>
  <div class="flex gap-8">
    ${queue.allDone ? `<button class="wpfd-btn wpfd-btn-secondary" id="btn-clear-queue"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> Clear</button>` : ''}
    <button class="wpfd-btn wpfd-btn-secondary" id="btn-to-deploy">+ Add More</button>
  </div>
</div>
${qphd}
<div class="wpfd-queue-wrap" id="wpfd-job-list">
  ${queue.jobs.map(j => render_job_card(j)).join('')}
</div>`;

  tab.querySelector('#btn-to-deploy')?.addEventListener('click', () => switch_tab('deploy'));
  tab.querySelector('#btn-clear-queue')?.addEventListener('click', () => {
    queue.jobs.splice(0);
    render_queue_tab();
  });

  /* Job card expand toggles */
  tab.querySelectorAll('.wpfd-job-head').forEach(hd => {
    hd.addEventListener('click', () => {
      const card = hd.closest('.wpfd-job-card');
      const job  = queue.jobs.find(j => j.id === card?.dataset.jobId);
      if (job) { job.expanded = !job.expanded; render_queue_tab(); }
    });
  });

  /* Rollback buttons */
  tab.querySelectorAll('[data-rollback-slug]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm(`Rollback "${btn.dataset.rollbackSlug}"?`)) return;
      btn.disabled = true; btn.textContent = '…';
      try {
        const res = await api('POST', '/rollback', {plugin_slug:btn.dataset.rollbackSlug, backup_path:btn.dataset.rollbackBak});
        res.success ? toast('success','Rolled back',res.message) : toast('error','Rollback failed',res.message);
      } catch(e) { toast('error','Rollback error',e.message); }
    });
  });

  render_sidebar_footer();
}

function render_job_card(job) {
  const pct  = job.pct();
  const elp  = job.elapsed();
  const spd  = job.speed();
  const etaMs = job.eta();
  const etaStr = etaMs ? 'ETA '+fmt_ms(etaMs) : '';

  const log_html = job.log.slice(-80).map(l => `
    <div class="wpfd-log-line ${esc_html(l.type)}">
      <span class="ll-time">${esc_html(l.ts)}</span>
      <span class="ll-path">${esc_html(l.path||l.msg)}</span>
    </div>`).join('');

  return `
<div class="wpfd-job-card ${job.status} ${job.expanded?'expanded':''}" data-job-id="${job.id}">
  <div class="wpfd-job-head">
    <span class="wpfd-job-dot"></span>
    <span class="wpfd-job-slug">${job.slug}</span>
    <div class="wpfd-job-meta">
      <span>${job.total||'?'} files</span>
      ${job.status==='running'&&spd ? `<span>${fmt_b(spd)}/s</span>` : ''}
      ${etaStr ? `<span>${etaStr}</span>` : ''}
      ${elp&&job.status!=='queued' ? `<span>${fmt_ms(elp)}</span>` : ''}
      <span class="wpfd-badge ${job.status==='done'?'badge-green':job.status==='running'?'badge-blue':job.status==='failed'?'badge-red':'badge-gray'}">${job.status}</span>
    </div>
    <span class="wpfd-job-pct">${job.status!=='queued'?pct+'%':''}</span>
    <span class="wpfd-job-toggle">▾</span>
  </div>

  <div class="wpfd-job-bar-track" style="margin:0;border-radius:0;height:2px">
    <div class="wpfd-job-bar-fill" style="width:${pct}%;transition:width .12s"></div>
  </div>

  <div class="wpfd-job-body">
    <div class="wpfd-job-bar-track" style="margin:12px 0 10px">
      <div class="wpfd-job-bar-fill" style="width:${pct}%"></div>
    </div>
    <div class="wpfd-job-stats">
      <div><strong>${job.written}</strong>written</div>
      <div><strong>${job.failed}</strong>failed</div>
      <div><strong>${job.skipped}</strong>skipped</div>
      <div><strong>${fmt_b(job.bytesWritten)}</strong>uploaded</div>
      <div><strong>${job.mode.toUpperCase()}</strong>mode</div>
    </div>
    <div class="wpfd-job-log">${log_html||'<div class="wpfd-log-line info"><span class="ll-path">Waiting…</span></div>'}</div>
    ${job.status==='done'&&job.backupPath ? `<div class="mt-12 flex gap-8">
      <button class="wpfd-btn wpfd-btn-sm wpfd-btn-danger" data-rollback-slug="${job.slug}" data-rollback-bak="${job.backupPath}">↩ Rollback</button>
    </div>` : ''}
    ${job.status==='done'&&appState.opts.activate ? `<div class="mt-12"><span class="wpfd-badge badge-green"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg> Activated</span></div>` : ''}
  </div>
</div>`;
}

/* ════════════════════════════════════════════════════════════
   PLUGINS TAB
   ════════════════════════════════════════════════════════════ */
async function load_plugins() {
  const tab = document.getElementById('tab-plugins');
  tab.innerHTML = `<div class="wpfd-page-hd"><div><h1 class="wpfd-page-title">Installed Plugins</h1><p class="wpfd-page-sub">Loading…</p></div></div>`;
  try {
    appState.plugins = await api('GET','/plugins',null);
    const nc = document.getElementById('nav-count-plugins');
    if (nc) nc.textContent = appState.plugins.length;
    render_plugins_table();
  } catch(e) {
    tab.innerHTML = `<p style="color:var(--red);padding:20px">Failed: ${e.message}</p>`;
  }
}

function render_plugins_table() {
  const tab = document.getElementById('tab-plugins');
  const active = appState.plugins.filter(p=>p.active).length;
  tab.innerHTML = `
<div class="wpfd-page-hd">
  <div>
    <h1 class="wpfd-page-title">Installed Plugins</h1>
    <p class="wpfd-page-sub">${appState.plugins.length} plugins · <span style="color:var(--green)">${active} active</span></p>
  </div>
  <button class="wpfd-btn wpfd-btn-secondary wpfd-btn-sm" id="btn-refresh-plugins"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Refresh</button>
</div>
<div class="wpfd-table-outer">
  <table class="wpfd-table">
    <thead><tr><th>Plugin</th><th>Slug</th><th>Version</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    ${appState.plugins.map(p => `
    <tr>
      <td style="font-weight:500;color:var(--t1)">${esc_html(p.name)}</td>
      <td><span class="wpfd-slug-text">${esc_html(p.slug)}</span></td>
      <td><span class="mono muted">${esc_html(p.version||'—')}</span></td>
      <td><span class="wpfd-badge ${p.active?'badge-green':'badge-gray'}">${p.active?'● Active':'○ Inactive'}</span></td>
      <td>
        <div class="flex gap-8">
          <button class="wpfd-btn wpfd-btn-xs ${p.active?'wpfd-btn-secondary':'wpfd-btn-primary'}"
                  data-action="${p.active?'deactivate':'activate'}" data-file="${esc_html(p.file)}">
            ${p.active?'Deactivate':'Activate'}
          </button>
          <button class="wpfd-btn wpfd-btn-xs wpfd-btn-secondary" data-view-bak="${esc_html(p.slug)}">Backups</button>
        </div>
      </td>
    </tr>`).join('')}
    </tbody>
  </table>
</div>`;

  tab.querySelector('#btn-refresh-plugins')?.addEventListener('click', load_plugins);

  tab.querySelectorAll('[data-action]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const action = btn.dataset.action;
      btn.disabled = true; btn.textContent = '…';
      try {
        await api('POST', '/'+action, {plugin_file:btn.dataset.file});
        toast(action==='activate'?'success':'info', action==='activate'?'Activated':'Deactivated', btn.dataset.file);
        load_plugins();
      } catch(e) { toast('error','Failed',e.message); btn.disabled=false; }
    });
  });

  tab.querySelectorAll('[data-view-bak]').forEach(btn => {
    btn.addEventListener('click', () => switch_tab('backups'));
  });
}

/* ════════════════════════════════════════════════════════════
   HISTORY TAB
   ════════════════════════════════════════════════════════════ */
async function load_history() {
  const tab = document.getElementById('tab-history');
  tab.innerHTML = `<div class="wpfd-page-hd"><div><h1 class="wpfd-page-title">Deploy History</h1><p class="wpfd-page-sub">Loading…</p></div></div>`;
  try {
    appState.history = await api('GET','/history',null);
    const nc = document.getElementById('nav-count-history');
    if (nc) nc.textContent = appState.history.length;
    render_history_table();
  } catch(e) {
    tab.innerHTML = `<p style="color:var(--red);padding:20px">Failed: ${e.message}</p>`;
  }
}

function render_history_table() {
  const tab = document.getElementById('tab-history');
  if (!appState.history.length) {
    tab.innerHTML = `<h1 class="wpfd-page-title">Deploy History</h1><div class="wpfd-empty"><div class="wpfd-empty-icon"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><h3 class="wpfd-empty-title">No deployments yet</h3><p>Deploy your first plugin to see history here.</p></div>`;
    return;
  }
  tab.innerHTML = `
<div class="wpfd-page-hd">
  <div><h1 class="wpfd-page-title">Deploy History</h1>
  <p class="wpfd-page-sub">${appState.history.length} deployments recorded</p></div>
  <button class="wpfd-btn wpfd-btn-secondary wpfd-btn-sm" id="btn-refresh-history"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Refresh</button>
</div>
<div class="wpfd-table-outer">
  <table class="wpfd-table">
    <thead><tr><th>Plugin</th><th>Files</th><th>Mode</th><th>Skipped</th><th>Date</th><th>By</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    ${appState.history.map(d => `
    <tr>
      <td><span class="wpfd-slug-text">${esc_html(d.plugin_slug)}</span></td>
      <td>${d.file_count}</td>
      <td><span class="wpfd-badge badge-blue">${esc_html((d.deploy_mode||'batch').toUpperCase())}</span></td>
      <td>${d.skipped||0}</td>
      <td class="muted" style="font-size:12px">${esc_html(d.deploy_time)}</td>
      <td class="muted" style="font-size:12px">${esc_html(d.user_login||'—')}</td>
      <td><span class="wpfd-badge ${d.status==='success'?'badge-green':'badge-red'}">${d.status}</span></td>
      <td>
        <div class="flex gap-8">
          ${d.backup_path
            ? `<button class="wpfd-btn wpfd-btn-xs wpfd-btn-danger" data-rl-slug="${esc_html(d.plugin_slug)}" data-rl-bak="${esc_html(d.backup_path)}">↩ Rollback</button>`
            : ''}
          <button class="wpfd-btn wpfd-btn-xs wpfd-btn-destructive" data-hist-del="${d.id}" title="Delete record">✕</button>
          <button class="wpfd-btn wpfd-btn-xs wpfd-btn-destructive" data-hist-nuke-slug="${esc_html(d.plugin_slug)}" title="Nuke deployed plugin">💣</button>
        </div>
      </td>
    </tr>`).join('')}
    </tbody>
  </table>
</div>`;

  tab.querySelector('#btn-refresh-history')?.addEventListener('click', load_history);

  tab.querySelectorAll('[data-rl-slug]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm(`Rollback "${btn.dataset.rlSlug}"?`)) return;
      btn.disabled=true; btn.textContent='…';
      try {
        const res = await api('POST','/rollback',{plugin_slug:btn.dataset.rlSlug, backup_path:btn.dataset.rlBak});
        res.success ? toast('success','Rolled back',res.message) : toast('error','Rollback failed',res.message);
        load_history();
      } catch(e) { toast('error','Error',e.message); btn.disabled=false; }
    });
  });

  /* Delete history record */
  tab.querySelectorAll('[data-hist-del]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = parseInt(btn.dataset.histDel, 10);
      if (!id || !confirm('Delete this deployment record?')) return;
      btn.disabled = true;
      try {
        await api('POST', '/history/delete', { ids: [id] });
        toast('info', 'Record deleted', '');
        load_history();
      } catch (e) { toast('error', 'Delete failed', e.message); btn.disabled = false; }
    });
  });

  /* Nuke deployed plugin from history */
  tab.querySelectorAll('[data-hist-nuke-slug]').forEach(btn => {
    btn.addEventListener('click', () => {
      const slug = btn.dataset.histNukeSlug;
      show_nuke_modal('plugins', slug, 'dir');
    });
  });
}

/* ════════════════════════════════════════════════════════════
   BACKUPS TAB
   ════════════════════════════════════════════════════════════ */
async function load_backups() {
  const tab = document.getElementById('tab-backups');
  tab.innerHTML = `<div class="wpfd-page-hd"><div><h1 class="wpfd-page-title">Backups</h1><p class="wpfd-page-sub">Loading…</p></div></div>`;
  try {
    appState.backups = await api('GET','/backups',null);
    const nc = document.getElementById('nav-count-backups');
    if (nc) nc.textContent = appState.backups.length;
    render_backups_table();
  } catch(e) {
    tab.innerHTML = `<p style="color:var(--red);padding:20px">Failed: ${e.message}</p>`;
  }
}

function render_backups_table() {
  const tab = document.getElementById('tab-backups');
  if (!appState.backups.length) {
    tab.innerHTML = `<h1 class="wpfd-page-title">Backups</h1><div class="wpfd-empty"><div class="wpfd-empty-icon"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div><h3 class="wpfd-empty-title">No backups yet</h3><p>Backups are created automatically when you deploy.</p></div>`;
    return;
  }
  const totalSz = appState.backups.reduce((s,b)=>s+b.size,0);
  tab.innerHTML = `
<div class="wpfd-page-hd">
  <div><h1 class="wpfd-page-title">Backups</h1>
  <p class="wpfd-page-sub">${appState.backups.length} backups · ${fmt_b(totalSz)} total</p></div>
  <button class="wpfd-btn wpfd-btn-secondary wpfd-btn-sm" id="btn-refresh-backups"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Refresh</button>
</div>
<div class="wpfd-table-outer">
  <table class="wpfd-table">
    <thead><tr><th>Name</th><th>Plugin</th><th>Files</th><th>Size</th><th>Actions</th></tr></thead>
    <tbody>
    ${appState.backups.map(b => `
    <tr>
      <td><span class="mono" style="font-size:11px;color:var(--t2)">${esc_html(b.name)}</span></td>
      <td><span class="wpfd-slug-text">${esc_html(b.slug)}</span></td>
      <td>${b.files}</td>
      <td>${fmt_b(b.size)}</td>
      <td>
        <div class="flex gap-8">
          <button class="wpfd-btn wpfd-btn-xs wpfd-btn-danger" data-rb-slug="${esc_html(b.slug)}" data-rb-bak="${esc_html(b.path)}">↩ Restore</button>
          <button class="wpfd-btn wpfd-btn-xs wpfd-btn-ghost" data-del-bak="${esc_html(b.path)}"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
        </div>
      </td>
    </tr>`).join('')}
    </tbody>
  </table>
</div>`;

  tab.querySelector('#btn-refresh-backups')?.addEventListener('click', load_backups);

  tab.querySelectorAll('[data-rb-slug]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm(`Restore "${btn.dataset.rbSlug}" from this backup?`)) return;
      btn.disabled=true; btn.textContent='…';
      try {
        const res = await api('POST','/rollback',{plugin_slug:btn.dataset.rbSlug, backup_path:btn.dataset.rbBak});
        res.success ? toast('success','Restored',res.message) : toast('error','Restore failed',res.message);
      } catch(e) { toast('error','Error',e.message); }
      btn.disabled=false; btn.textContent='↩ Restore';
    });
  });

  tab.querySelectorAll('[data-del-bak]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this backup permanently?')) return;
      try {
        await api('POST','/backups/delete',{backup_path:btn.dataset.delBak});
        toast('info','Deleted','');
        load_backups();
      } catch(e) { toast('error','Delete failed',e.message); }
    });
  });
}

/* ════════════════════════════════════════════════════════════
   FILE BROWSER TAB — Section B
   B1 WPFD_Browser PHP class · B2 REST routes
   B3 Two-panel UI · B4 Nuke / Copy Path / Deploy Here
   ════════════════════════════════════════════════════════════ */

/* ── Helpers ─────────────────────────────────────────────── */
function root_label(alias) {
  const L = { plugins:'Plugins', themes:'Themes', uploads:'Uploads', 'mu-plugins':'MU-Plugins', content:'wp-content', root:'ABSPATH' };
  return L[alias] || alias;
}
function root_icon(alias) {
  const icons = {
    plugins:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
    themes:     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
    uploads:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
    'mu-plugins':'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
    content:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
    root:       '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
  };
  return icons[alias] || icons.content;
}
function fmt_date(ts) {
  const d = new Date(ts * 1000);
  return d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
}

/* ── Load browser tab (async entry point) ────────────────── */
async function load_browser() {
  const tab = document.getElementById('tab-browser');
  if (!tab) return;

  /* Show skeleton while fetching roots */
  if (!Object.keys(appState.browser.roots).length) {
    tab.innerHTML = `<div class="wpfd-page-hd"><div><h1 class="wpfd-page-title">File Browser</h1><p class="wpfd-page-sub">Browse and manage server-side files</p></div></div><div class="wpfd-browser-loading"><span class="wpfd-spinner-sm"></span> Loading roots…</div>`;
    try {
      const res = await api('GET', '/browser/roots', null);
      appState.browser.roots = res.roots || {};
    } catch(e) {
      tab.innerHTML += `<div class="wpfd-alert-error" style="margin:24px">Failed to load roots: ${e.message||'Network error'}</div>`;
      return;
    }
  }

  /* Trigger initial scan of default root if no entries loaded yet */
  if (!appState.browser.entries.length && !appState.browser.loading) {
    browser_scan(appState.browser.root, '');
  } else {
    render_browser_tab();
  }
}

/* ── Render the full browser tab ─────────────────────────── */
function render_browser_tab() {
  const tab = document.getElementById('tab-browser');
  if (!tab) return;
  const { root, path, entries, loading, breadcrumbs } = appState.browser;

  const root_btns = ['plugins','themes','uploads','mu-plugins','content','root'].map(r =>
    `<button class="wpfd-root-btn${root===r?' active':''}" data-root="${r}">${root_icon(r)}<span>${root_label(r)}</span></button>`
  ).join('');

  tab.innerHTML = `
<div class="wpfd-page-hd">
  <div>
    <h1 class="wpfd-page-title">File Browser</h1>
    <p class="wpfd-page-sub">Browse, inspect, and manage server-side files</p>
  </div>
</div>
<div class="wpfd-browser-panels">
  <div class="wpfd-browser-roots">
    <div class="wpfd-browser-roots-label">Root</div>
    ${root_btns}
  </div>
  <div class="wpfd-browser-content">
    <div class="wpfd-browser-toolbar">
      ${render_breadcrumbs(root, breadcrumbs)}
      <input class="wpfd-browser-search" type="text" id="browser-search" placeholder="Filter files…" value="${appState.browser.search || ''}">
      <select class="wpfd-browser-sort" id="browser-sort">
        <option value="name"${appState.browser.sort==='name'?' selected':''}>Name</option>
        <option value="size"${appState.browser.sort==='size'?' selected':''}>Size</option>
        <option value="date"${appState.browser.sort==='date'?' selected':''}>Date</option>
      </select>
      <div class="wpfd-browser-bulk-actions">
        <label title="Select / deselect all" style="display:flex;align-items:center;gap:4px;font-size:11px;color:var(--t3);cursor:pointer">
          <input type="checkbox" id="browser-check-all"> All
        </label>
        <button class="wpfd-btn wpfd-btn-xs wpfd-btn-download" id="browser-dl-selected" disabled>⬇ Download</button>
        <button class="wpfd-btn wpfd-btn-xs wpfd-btn-destructive" id="browser-nuke-selected" disabled>🗑 Delete</button>
      </div>
    </div>
    ${loading
      ? `<div class="wpfd-browser-loading"><span class="wpfd-spinner-sm"></span> Scanning…</div>`
      : render_browser_entries(entries, root, path)
    }
  </div>
</div>`;

  /* Root buttons */
  tab.querySelectorAll('[data-root]').forEach(btn => {
    btn.addEventListener('click', () => {
      const r = btn.dataset.root;
      appState.browser = { ...appState.browser, root: r, path: '', breadcrumbs: [], entries: [] };
      browser_scan(r, '');
    });
  });

  /* Breadcrumb navigation */
  tab.querySelectorAll('[data-crumb]').forEach(el => {
    el.addEventListener('click', () => {
      const idx   = parseInt(el.dataset.crumb, 10);
      const crumbs = appState.browser.breadcrumbs.slice(0, idx);
      const p      = crumbs.length ? crumbs[crumbs.length - 1].path : '';
      appState.browser = { ...appState.browser, path: p, breadcrumbs: crumbs };
      browser_scan(root, p);
    });
  });

  /* Directory drill-down */
  tab.querySelectorAll('[data-browse-dir]').forEach(el => {
    el.addEventListener('click', () => {
      const dir_path = el.dataset.browseDir;
      const dir_name = el.dataset.browseName;
      const crumbs   = [...appState.browser.breadcrumbs, { name: dir_name, path: dir_path }];
      appState.browser = { ...appState.browser, path: dir_path, breadcrumbs: crumbs };
      browser_scan(root, dir_path);
    });
  });

  /* Copy path */
  tab.querySelectorAll('[data-copy-path]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      navigator.clipboard.writeText(btn.dataset.copyPath).then(() => toast('success','Copied', btn.dataset.copyPath));
    });
  });

  /* Deploy Here — sets override slug, switches to deploy tab */
  tab.querySelectorAll('[data-deploy-here]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      const slug = btn.dataset.deployHere;
      appState.browser.deployHereSlug = slug;
      switch_tab('deploy');
      toast('info', 'Deploy target set', `Next folder you stage will deploy to "${slug}". Drop your local folder now.`);
    });
  });

  /* Nuke */
  tab.querySelectorAll('[data-nuke-path]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      show_nuke_modal(root, btn.dataset.nukePath, btn.dataset.nukeType);
    });
  });

  /* Download single item */
  tab.querySelectorAll('[data-dl-path]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      download_item(root, btn.dataset.dlPath, btn.dataset.dlName);
    });
  });

  /* Extract Code — single file */
  tab.querySelectorAll('[data-extract-path]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      extract_code(root, btn.dataset.extractPath, btn.dataset.extractName);
    });
  });

  /* Extract Code — directory */
  tab.querySelectorAll('[data-extract-dir]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      extract_code_dir(root, btn.dataset.extractDir, btn.dataset.extractName);
    });
  });

  /* Row checkboxes */
  tab.querySelectorAll('[data-check-rel]').forEach(cb => {
    cb.addEventListener('change', () => {
      if (cb.checked) appState.browser.checked.add(cb.dataset.checkRel);
      else appState.browser.checked.delete(cb.dataset.checkRel);
      const row = cb.closest('.wpfd-brow-row');
      if (row) row.classList.toggle('is-checked', cb.checked);
      update_bulk_buttons(tab);
    });
  });

  /* Select all checkbox */
  const checkAll = tab.querySelector('#browser-check-all');
  if (checkAll) {
    checkAll.addEventListener('change', () => {
      tab.querySelectorAll('[data-check-rel]').forEach(cb => {
        cb.checked = checkAll.checked;
        if (checkAll.checked) appState.browser.checked.add(cb.dataset.checkRel);
        else appState.browser.checked.delete(cb.dataset.checkRel);
        const row = cb.closest('.wpfd-brow-row');
        if (row) row.classList.toggle('is-checked', checkAll.checked);
      });
      update_bulk_buttons(tab);
    });
  }

  /* Search filter */
  const searchInput = tab.querySelector('#browser-search');
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      appState.browser.search = searchInput.value;
      render_browser_tab();
      /* refocus search after re-render */
      const el = document.querySelector('#browser-search');
      if (el) { el.focus(); el.selectionStart = el.selectionEnd = el.value.length; }
    });
  }

  /* Sort select */
  const sortSel = tab.querySelector('#browser-sort');
  if (sortSel) {
    sortSel.addEventListener('change', () => {
      appState.browser.sort = sortSel.value;
      render_browser_tab();
    });
  }

  /* Bulk download selected */
  const dlSelBtn = tab.querySelector('#browser-dl-selected');
  if (dlSelBtn) {
    dlSelBtn.addEventListener('click', () => download_selected());
  }

  /* Bulk nuke selected */
  const nukeSelBtn = tab.querySelector('#browser-nuke-selected');
  if (nukeSelBtn) {
    nukeSelBtn.addEventListener('click', () => nuke_selected());
  }

  /* Keyboard navigation */
  const browList = tab.querySelector('.wpfd-brow-list');
  if (browList) {
    browList.setAttribute('tabindex', '0');
    browList.addEventListener('keydown', e => {
      const rows = [...browList.querySelectorAll('.wpfd-brow-row')];
      if (!rows.length) return;
      const focused = browList.querySelector('.wpfd-brow-row.kb-focus');
      let idx = focused ? rows.indexOf(focused) : -1;
      if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(idx + 1, rows.length - 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(idx - 1, 0); }
      else if (e.key === 'Enter' && focused) {
        const dirEl = focused.querySelector('[data-browse-dir]');
        if (dirEl) dirEl.click();
        return;
      }
      else return;
      rows.forEach(r => r.classList.remove('kb-focus'));
      if (rows[idx]) {
        rows[idx].classList.add('kb-focus');
        rows[idx].scrollIntoView({ block: 'nearest' });
      }
    });
  }
}

/* ── Render directory entries ────────────────────────────── */
function render_browser_entries(entries, root, cur_path) {
  if (!entries.length) {
    return `<div class="wpfd-tray-empty" style="min-height:180px"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg><p>Empty directory</p></div>`;
  }

  /* Filter by search */
  let filtered = entries;
  const q = (appState.browser.search || '').toLowerCase();
  if (q) filtered = entries.filter(e => e.name.toLowerCase().includes(q));

  /* Sort */
  const sortKey = appState.browser.sort || 'name';
  filtered = [...filtered].sort((a, b) => {
    /* dirs first always */
    if (a.type === 'dir' && b.type !== 'dir') return -1;
    if (a.type !== 'dir' && b.type === 'dir') return 1;
    if (sortKey === 'size') return (b.size || 0) - (a.size || 0);
    if (sortKey === 'date') return (b.modified || 0) - (a.modified || 0);
    return a.name.localeCompare(b.name);
  });

  if (!filtered.length) {
    return `<div class="wpfd-tray-empty" style="min-height:120px"><p>No matches for "${q}"</p></div>`;
  }

  const COPYico = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>`;
  const UPico   = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>`;
  const NUKEico = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>`;
  const DLico   = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`;
  const EXTico  = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>`;

  const rows = filtered.map(e => {
    const nameH = esc_html(e.name);
    const rel = cur_path ? `${cur_path}/${e.name}` : e.name;
    const relH = esc_html(rel);
    const absH  = esc_html(`${appState.browser.roots[root] || ''}/${rel}`);
    const chk  = appState.browser.checked.has(rel) ? 'checked' : '';
    const chkCls = chk ? ' is-checked' : '';
    const permsHtml  = `<span class="wpfd-brow-perms">${esc_html(e.perms)}</span>`;
    const dateHtml   = `<span class="wpfd-brow-date">${fmt_date(e.modified)}</span>`;

    if (e.type === 'dir') {
      return `<div class="wpfd-brow-row wpfd-brow-dir${chkCls}" data-rel="${relH}">
  <span class="wpfd-brow-chk"><input type="checkbox" data-check-rel="${relH}" ${chk}></span>
  <span class="wpfd-brow-icon">📁</span>
  <span class="wpfd-brow-name" data-browse-dir="${relH}" data-browse-name="${nameH}">${nameH}</span>
  <span class="wpfd-brow-size"></span>
  ${permsHtml}${dateHtml}
  <div class="wpfd-brow-acts">
    <button class="wpfd-btn wpfd-btn-xs wpfd-btn-download" data-dl-path="${relH}" data-dl-name="${nameH}" title="Download">${DLico}</button>
    <button class="wpfd-btn wpfd-btn-xs wpfd-btn-ghost" data-copy-path="${absH}" title="Copy path">${COPYico}</button>
    <button class="wpfd-btn wpfd-btn-xs wpfd-btn-ghost" data-deploy-here="${nameH}" title="Deploy Here">${UPico}</button>
    <button class="wpfd-btn wpfd-btn-xs wpfd-btn-ghost" data-extract-dir="${relH}" data-extract-name="${nameH}" title="Extract Code">${EXTico}</button>
    <button class="wpfd-btn wpfd-btn-xs wpfd-btn-destructive" data-nuke-path="${relH}" data-nuke-type="dir" title="Nuke folder">${NUKEico}</button>
  </div>
</div>`;
    } else {
      return `<div class="wpfd-brow-row wpfd-brow-file${chkCls}" data-rel="${relH}">
  <span class="wpfd-brow-chk"><input type="checkbox" data-check-rel="${relH}" ${chk}></span>
  <span class="wpfd-brow-icon">${ext_icon(e.name)}</span>
  <span class="wpfd-brow-name">${nameH}</span>
  <span class="wpfd-brow-size">${fmt_b(e.size)}</span>
  ${permsHtml}${dateHtml}
  <div class="wpfd-brow-acts">
    <button class="wpfd-btn wpfd-btn-xs wpfd-btn-download" data-dl-path="${relH}" data-dl-name="${nameH}" title="Download">${DLico}</button>
    <button class="wpfd-btn wpfd-btn-xs wpfd-btn-ghost" data-copy-path="${absH}" title="Copy path">${COPYico}</button>
    <button class="wpfd-btn wpfd-btn-xs wpfd-btn-ghost" data-extract-path="${relH}" data-extract-name="${nameH}" title="Extract Code">${EXTico}</button>
    <button class="wpfd-btn wpfd-btn-xs wpfd-btn-destructive" data-nuke-path="${relH}" data-nuke-type="file" title="Delete file">${NUKEico}</button>
  </div>
</div>`;
    }
  }).join('');

  return `<div class="wpfd-brow-header"><span></span><span></span><span>Name</span><span>Size</span><span>Perms</span><span>Modified</span><span></span></div><div class="wpfd-brow-list">${rows}</div>`;
}

/* ── Breadcrumb bar ──────────────────────────────────────── */
function render_breadcrumbs(root, crumbs) {
  const home  = `<span class="wpfd-crumb" data-crumb="0">${root_label(root)}</span>`;
  const trail = crumbs.map((c, i) =>
    `<span class="wpfd-crumb-sep">›</span><span class="wpfd-crumb" data-crumb="${i + 1}">${esc_html(c.name)}</span>`
  ).join('');
  return `<nav class="wpfd-breadcrumbs">${home}${trail}</nav>`;
}

/* ── API helpers ─────────────────────────────────────────── */
async function browser_scan(root, path) {
  appState.browser.loading = true;
  if (appState.tab === 'browser') render_browser_tab();
  try {
    const qs  = new URLSearchParams({ root, path: path || '' });
    const res = await api('GET', `/browser/scan?${qs}`, null);
    appState.browser.entries = res.entries || [];
    appState.browser.loading = false;
    if (appState.tab === 'browser') render_browser_tab();
  } catch(e) {
    appState.browser.loading = false;
    appState.browser.entries = [];
    toast('error', 'Scan failed', e.message);
    if (appState.tab === 'browser') render_browser_tab();
  }
}

async function browser_nuke(root, relpath, type, logEl) {
  try {
    const res = await api('POST', '/browser/nuke', { root, path: relpath, type });
    if (logEl && res.log && res.log.length) {
      logEl.textContent = res.log.join('\n');
    }
    toast('success', 'Deleted', relpath.split('/').pop() + (res.strategy_used ? ` (strategy ${res.strategy_used})` : ''));
    browser_scan(root, appState.browser.path);
    return res;
  } catch(e) {
    toast('error', 'Delete failed', e.message);
    if (logEl) logEl.textContent += '\n❌ ' + e.message;
    throw e;
  }
}

/* ── Nuke confirmation modal (C3) ────────────────────────── */
async function show_nuke_modal(root, relpath, type) {
  const name = relpath.split('/').pop();
  const isDir = type === 'dir';

  const backdrop = document.createElement('div');
  backdrop.className = 'wpfd-modal-backdrop';
  backdrop.innerHTML = `
    <div class="wpfd-modal" style="max-width:520px">
      <div class="wpfd-modal-title" style="color:var(--red)">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        Nuke ${isDir ? 'Folder' : 'File'}
      </div>
      <div class="wpfd-modal-body" style="margin-bottom:12px">
        <p style="margin:0 0 10px">Permanently delete <code style="font-family:var(--font-mono);font-size:12px;background:var(--surface-2);padding:2px 6px;border-radius:var(--r-sm)">${name}</code>? This cannot be undone.</p>
        <div id="nuke-scan-info" style="padding:10px 14px;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-lg);font-size:12.5px;color:var(--t2);margin-bottom:14px">
          <span style="color:var(--t3)">Scanning…</span>
        </div>
        <label class="wpfd-label" style="margin-bottom:4px">Type <strong>DELETE</strong> to confirm</label>
        <input class="wpfd-input" id="nuke-confirm-input" placeholder="DELETE" autocomplete="off" spellcheck="false">
      </div>
      <div id="nuke-log" style="display:none;max-height:120px;overflow-y:auto;padding:8px 10px;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-lg);font-family:var(--font-mono);font-size:11px;color:var(--t3);white-space:pre-wrap;margin-bottom:14px"></div>
      <div class="wpfd-modal-actions">
        <button class="wpfd-btn wpfd-btn-secondary" id="nuke-cancel">Cancel</button>
        <button class="wpfd-btn wpfd-btn-destructive" id="nuke-execute" disabled>Nuke</button>
      </div>
    </div>
  `;
  (document.getElementById('wpfd-root') || document.body).appendChild(backdrop);

  const input   = backdrop.querySelector('#nuke-confirm-input');
  const execBtn = backdrop.querySelector('#nuke-execute');
  const scanBox = backdrop.querySelector('#nuke-scan-info');
  const logBox  = backdrop.querySelector('#nuke-log');
  const close   = () => backdrop.remove();

  /* Enable button only when user types DELETE */
  input.addEventListener('input', () => {
    execBtn.disabled = input.value.trim() !== 'DELETE';
  });

  /* Run nuke-scan in background */
  api('POST', '/browser/nuke-scan', { root, path: relpath }).then(res => {
    if (!res || !res.success) { scanBox.textContent = 'Scan unavailable'; return; }
    const parts = [];
    if (res.is_dir) parts.push(`${res.file_count} file${res.file_count !== 1 ? 's' : ''}`);
    parts.push(fmt_b(res.total_bytes));
    if (res.readonly > 0) parts.push(`${res.readonly} read-only`);
    scanBox.innerHTML = parts.map(p =>
      `<span style="display:inline-block;background:var(--surface-3);padding:2px 8px;border-radius:var(--r-pill);margin-right:6px;font-family:var(--font-mono)">${p}</span>`
    ).join('');
  }).catch(() => { scanBox.textContent = 'Scan unavailable'; });

  input.focus();

  /* Execute nuke */
  execBtn.addEventListener('click', async () => {
    if (input.value.trim() !== 'DELETE') return;
    execBtn.disabled = true;
    execBtn.textContent = 'Nuking…';
    logBox.style.display = 'block';
    logBox.textContent = 'Starting nuke…';
    try {
      await browser_nuke(root, relpath, type, logBox);
      logBox.textContent += '\n✅ Done';
      setTimeout(close, 600);
    } catch {
      execBtn.textContent = 'Failed';
    }
  });

  backdrop.querySelector('#nuke-cancel').addEventListener('click', close);
  backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !execBtn.disabled) execBtn.click();
    if (e.key === 'Escape') close();
  });
}

/* ════════════════════════════════════════════════════════════
   DOWNLOAD SYSTEM
   ════════════════════════════════════════════════════════════ */

function update_bulk_buttons(tab) {
  const count = appState.browser.checked.size;
  const dlBtn = tab.querySelector('#browser-dl-selected');
  const nukeBtn = tab.querySelector('#browser-nuke-selected');
  if (dlBtn) { dlBtn.disabled = count === 0; dlBtn.textContent = count ? `⬇ Download (${count})` : '⬇ Download'; }
  if (nukeBtn) { nukeBtn.disabled = count === 0; nukeBtn.textContent = count ? `🗑 Delete (${count})` : '🗑 Delete'; }
}

async function download_item(root, relpath, name) {
  const id = uid();
  appState.downloadQueue.push({ id, name: name || relpath.split('/').pop(), status: 'pending', url: '' });
  update_dl_badge();
  try {
    const res = await api('POST', '/download/token', { root, path: relpath });
    if (!res.success) throw new Error(res.message || 'Token failed');
    const entry = appState.downloadQueue.find(d => d.id === id);
    if (entry) { entry.status = 'downloading'; entry.url = res.url; }
    update_dl_badge();
    /* open download in hidden iframe */
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = res.url;
    document.body.appendChild(iframe);
    setTimeout(() => iframe.remove(), 30000);
    const done = appState.downloadQueue.find(d => d.id === id);
    if (done) done.status = 'done';
    toast('success', 'Download started', name || relpath);
  } catch (e) {
    const err = appState.downloadQueue.find(d => d.id === id);
    if (err) err.status = 'error';
    toast('error', 'Download failed', e.message);
  }
  update_dl_badge();
  if (appState.tab === 'downloads') render_downloads_tab();
}

/* ── Extract Code — read file, wrap in Markdown, download .md ── */
const LANG_MAP = {
  php:'php', inc:'php', module:'php',
  js:'javascript', mjs:'javascript', cjs:'javascript', jsx:'jsx', ts:'typescript', tsx:'tsx',
  css:'css', scss:'scss', sass:'sass', less:'less',
  html:'html', htm:'html', twig:'twig', blade:'html',
  json:'json', xml:'xml', yaml:'yaml', yml:'yaml', toml:'toml',
  sql:'sql', sh:'bash', bash:'bash', zsh:'bash', ps1:'powershell',
  py:'python', rb:'ruby', java:'java', c:'c', h:'c', cpp:'cpp', cs:'csharp', go:'go', rs:'rust',
  swift:'swift', kt:'kotlin', lua:'lua', r:'r', pl:'perl',
  md:'markdown', txt:'text', csv:'csv', log:'text',
  env:'bash', htaccess:'apache', conf:'nginx',
  svg:'xml', map:'json', lock:'json', pot:'po',
};

async function extract_code(root, relpath, name) {
  toast('info', 'Extracting…', name);
  try {
    const res = await api('POST', '/browser/read-file', { root, path: relpath });
    if (!res.success) throw new Error(res.message || 'Read failed');
    const ext  = (name.split('.').pop() || '').toLowerCase();
    const lang = LANG_MAP[ext] || '';
    const md   = `# ${name}\n\n\`\`\`${lang}\n${res.content}\n\`\`\`\n`;
    const blob = new Blob([md], { type: 'text/markdown;charset=utf-8' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = name + '.md';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    toast('success', 'Extracted', name + '.md');
  } catch (e) {
    toast('error', 'Extract failed', e.message);
  }
}

async function extract_code_dir(root, relpath, name) {
  toast('info', 'Extracting folder…', name);
  try {
    const res = await api('POST', '/browser/extract-dir', { root, path: relpath });
    if (!res.success) throw new Error(res.message || 'Extract failed');
    const parts = [`# ${res.dirname || name}\n`];
    if (res.truncated) parts.push(`> ⚠️ Output was truncated (${res.count} files extracted).\n`);
    parts.push(`**${res.count} file${res.count !== 1 ? 's' : ''}** extracted\n\n---\n`);
    for (const f of res.files) {
      const ext  = (f.path.split('.').pop() || '').toLowerCase();
      const lang = LANG_MAP[ext] || '';
      parts.push(`## ${f.path}\n\n\`\`\`${lang}\n${f.content}\n\`\`\`\n\n---\n`);
    }
    const md   = parts.join('\n');
    const blob = new Blob([md], { type: 'text/markdown;charset=utf-8' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = name + '.md';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    toast('success', 'Extracted', `${name}.md (${res.count} files)`);
  } catch (e) {
    toast('error', 'Extract failed', e.message);
  }
}

async function download_selected() {
  const checked = [...appState.browser.checked];
  if (!checked.length) return;
  const root = appState.browser.root;
  if (checked.length === 1) {
    download_item(root, checked[0], checked[0].split('/').pop());
    return;
  }
  /* Multi-download */
  const items = checked.map(p => ({ root, path: p }));
  const id = uid();
  appState.downloadQueue.push({ id, name: `${checked.length} files (ZIP)`, status: 'pending', url: '' });
  update_dl_badge();
  try {
    const res = await api('POST', '/download/multi-token', { items });
    if (!res.success) throw new Error(res.message || 'Token failed');
    const entry = appState.downloadQueue.find(d => d.id === id);
    if (entry) { entry.status = 'downloading'; entry.url = res.url; }
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = res.url;
    document.body.appendChild(iframe);
    setTimeout(() => iframe.remove(), 60000);
    const done = appState.downloadQueue.find(d => d.id === id);
    if (done) done.status = 'done';
    toast('success', 'Multi-download started', `${checked.length} items`);
  } catch (e) {
    const err = appState.downloadQueue.find(d => d.id === id);
    if (err) err.status = 'error';
    toast('error', 'Download failed', e.message);
  }
  update_dl_badge();
  if (appState.tab === 'downloads') render_downloads_tab();
}

async function nuke_selected() {
  const checked = [...appState.browser.checked];
  if (!checked.length) return;
  show_bulk_nuke_modal(checked);
}

function show_bulk_nuke_modal(checked) {
  const count = checked.length;
  const root = appState.browser.root;

  const backdrop = document.createElement('div');
  backdrop.className = 'wpfd-modal-backdrop';
  backdrop.innerHTML = `
    <div class="wpfd-modal" style="max-width:520px">
      <div class="wpfd-modal-title" style="color:var(--red)">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        Bulk Delete ${count} Item${count !== 1 ? 's' : ''}
      </div>
      <div class="wpfd-modal-body" style="margin-bottom:12px">
        <p style="margin:0 0 10px">Permanently delete <strong>${count}</strong> selected item${count !== 1 ? 's' : ''}? This cannot be undone.</p>
        <label class="wpfd-label" style="margin-bottom:4px">Type <strong>DELETE</strong> to confirm</label>
        <input class="wpfd-input" id="bulk-nuke-confirm-input" placeholder="DELETE" autocomplete="off" spellcheck="false">
      </div>
      <div class="wpfd-modal-actions">
        <button class="wpfd-btn wpfd-btn-secondary" id="bulk-nuke-cancel">Cancel</button>
        <button class="wpfd-btn wpfd-btn-destructive" id="bulk-nuke-execute" disabled>Nuke ${count} Item${count !== 1 ? 's' : ''}</button>
      </div>
    </div>
  `;
  (document.getElementById('wpfd-root') || document.body).appendChild(backdrop);

  const input   = backdrop.querySelector('#bulk-nuke-confirm-input');
  const execBtn = backdrop.querySelector('#bulk-nuke-execute');
  const close   = () => backdrop.remove();

  input.addEventListener('input', () => {
    execBtn.disabled = input.value.trim() !== 'DELETE';
  });

  input.focus();

  execBtn.addEventListener('click', async () => {
    if (input.value.trim() !== 'DELETE') return;
    execBtn.disabled = true;
    execBtn.textContent = 'Nuking…';
    const items = checked.map(p => ({ root, path: p }));
    try {
      const res = await api('POST', '/browser/bulk-nuke', { items });
      toast(res.failed ? 'warning' : 'success', 'Bulk delete', `${res.deleted} deleted, ${res.failed} failed`);
      appState.browser.checked.clear();
      browser_scan(root, appState.browser.path);
      close();
    } catch (e) {
      toast('error', 'Bulk delete failed', e.message);
      execBtn.textContent = 'Failed';
    }
  });

  backdrop.querySelector('#bulk-nuke-cancel').addEventListener('click', close);
  backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !execBtn.disabled) execBtn.click();
    if (e.key === 'Escape') close();
  });
}

function update_dl_badge() {
  const nc = document.getElementById('nav-count-downloads');
  if (nc) nc.textContent = appState.downloadQueue.length || '0';
}

/* ── Downloads tab ───────────────────────────────────────── */
function render_downloads_tab() {
  const tab = document.getElementById('tab-downloads');
  if (!tab) return;
  const q = appState.downloadQueue;
  if (!q.length) {
    tab.innerHTML = `
<div class="wpfd-page-hd"><div><h1 class="wpfd-page-title">Downloads</h1><p class="wpfd-page-sub">Download files from the server browser</p></div></div>
<div class="wpfd-empty"><div class="wpfd-empty-icon"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></div><h3 class="wpfd-empty-title">No downloads yet</h3><p>Use the download button in the File Browser to queue files.</p></div>`;
    return;
  }
  const statusBadge = s => {
    const m = { pending:'badge-gray', downloading:'badge-blue', done:'badge-green', error:'badge-red' };
    return `<span class="wpfd-badge ${m[s]||'badge-gray'}">${s}</span>`;
  };
  tab.innerHTML = `
<div class="wpfd-page-hd">
  <div><h1 class="wpfd-page-title">Downloads</h1><p class="wpfd-page-sub">${q.length} item(s) in queue</p></div>
  <button class="wpfd-btn wpfd-btn-secondary wpfd-btn-sm" id="btn-clear-dl">Clear completed</button>
</div>
<div class="wpfd-dl-queue">
  ${q.map(d => `<div class="wpfd-dl-item"><span class="wpfd-dl-item-name">${d.name}</span>${statusBadge(d.status)}</div>`).join('')}
</div>`;

  tab.querySelector('#btn-clear-dl')?.addEventListener('click', () => {
    appState.downloadQueue = appState.downloadQueue.filter(d => d.status !== 'done' && d.status !== 'error');
    update_dl_badge();
    render_downloads_tab();
  });
}

/* ════════════════════════════════════════════════════════════
   SETTINGS TAB
   ════════════════════════════════════════════════════════════ */

async function load_settings() {
  const tab = document.getElementById('tab-settings');
  if (!tab) return;
  tab.innerHTML = `<div class="wpfd-page-hd"><div><h1 class="wpfd-page-title">Settings</h1><p class="wpfd-page-sub">Loading…</p></div></div>`;
  try {
    const res = await api('GET', '/settings', null);
    appState.settings = res.settings || appState.settings;
    render_settings_tab();
  } catch (e) {
    tab.innerHTML = `<p style="color:var(--red);padding:20px">Failed to load settings: ${e.message}</p>`;
  }
}

function render_settings_tab() {
  const tab = document.getElementById('tab-settings');
  if (!tab) return;
  const s = appState.settings;
  tab.innerHTML = `
<div class="wpfd-page-hd">
  <div><h1 class="wpfd-page-title">Settings</h1><p class="wpfd-page-sub">Configure Folder Deployer behaviour</p></div>
</div>
<div class="wpfd-settings-form">
  <div class="wpfd-settings-group">
    <label for="set-retention">Backup Retention</label>
    <input class="wpfd-input" id="set-retention" type="number" min="1" max="50" value="${s.backup_retention || 5}">
    <span class="wpfd-help">Number of backups to keep per plugin before auto-pruning (1–50).</span>
  </div>
  <div style="padding-top:8px">
    <button class="wpfd-btn wpfd-btn-primary" id="btn-save-settings">Save Settings</button>
    <span id="settings-status" style="margin-left:12px;font-size:12px;color:var(--t3)"></span>
  </div>
</div>`;

  tab.querySelector('#btn-save-settings')?.addEventListener('click', async () => {
    const retention = parseInt(tab.querySelector('#set-retention')?.value || '5', 10);
    const statusEl = tab.querySelector('#settings-status');
    try {
      const res = await api('POST', '/settings', { backup_retention: retention });
      appState.settings = res.settings || appState.settings;
      if (statusEl) { statusEl.textContent = '✅ Saved'; setTimeout(() => statusEl.textContent = '', 2000); }
      toast('success', 'Settings saved', '');
    } catch (e) {
      if (statusEl) statusEl.textContent = '❌ ' + e.message;
      toast('error', 'Save failed', e.message);
    }
  });
}

/* ════════════════════════════════════════════════════════════
   BOOT
   ════════════════════════════════════════════════════════════ */
function boot() {
  if (!document.getElementById('wpfd-root')) return;

  /* Prevent Chrome from navigating to a dropped folder when the user
     misses the drop zone — stop ALL drag/drop at document level first,
     then the arena's own handlers re-enable ingest only inside the zone. */
  document.addEventListener('dragenter', e => e.preventDefault(), false);
  document.addEventListener('dragover',  e => e.preventDefault(), false);
  document.addEventListener('dragleave', e => e.preventDefault(), false);
  document.addEventListener('drop',      e => e.preventDefault(), false);

  render_shell();
}

document.readyState === 'loading'
  ? document.addEventListener('DOMContentLoaded', boot)
  : boot();

})();
