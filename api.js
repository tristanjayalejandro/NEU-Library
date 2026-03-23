const API_BASE = 'api.php';
async function api(action, opts = {}) {
  const { body, params = {} } = opts;
  let url = `${API_BASE}?action=${action}`;
  for (const [k,v] of Object.entries(params)) if (v!==''&&v!=null) url+=`&${k}=${encodeURIComponent(v)}`;
  const cfg = { method: body?'POST':'GET', headers:{'Content-Type':'application/json'} };
  if (body) cfg.body = JSON.stringify(body);
  try { const res = await fetch(url,cfg); return await res.json(); }
  catch(e) { return {success:false, error:'Network error.'}; }
}
async function requireAuth() {
  const r = await api('check_session');
  if (!r.logged_in) window.location.href='index.html';
  return r;
}
function doLogout() { api('logout',{body:{}}).then(()=>window.location.href='index.html'); }
function fmtDate(d) { if(!d) return '—'; return new Date(d).toLocaleString(); }
function fmtDateShort(d) { if(!d) return '—'; return new Date(d).toLocaleDateString(); }
function esc(str) { if(str===null||str===undefined) return ''; return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function toast(msg,type='ok') { const c=document.getElementById('toast'); if(!c) return; const el=document.createElement('div'); el.className=`toast-item toast-${type}`; el.textContent=msg; c.appendChild(el); setTimeout(()=>el.remove(),3500); }
function showAlert(id,msg) { const el=document.getElementById(id); if(el){el.textContent=msg;el.style.display='flex';} }
function hideAlert(id) { const el=document.getElementById(id); if(el) el.style.display='none'; }
function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }
function setActiveNav() { const page=window.location.pathname.split('/').pop(); document.querySelectorAll('.nav-btn').forEach(btn=>btn.classList.toggle('active',(btn.getAttribute('href')||'')===page)); }
