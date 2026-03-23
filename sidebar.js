async function injectSidebar() {
  const r = await api('check_session');
  if (!r.logged_in) { window.location.href = 'index.html'; return; }
  if (r.active_role !== 'admin') { window.location.href = 'welcome.html'; return; }

  const initials = r.name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0,2);
  const avatarHtml = r.avatar
    ? `<img src="${r.avatar}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.parentElement.textContent='${initials}'"/>`
    : initials;

  const html = `
  <div id="sidebar">
    <div class="sidebar-logo">
      <div class="logo-icon" style="background:none;padding:2px;">
        <img src="images/neu-logo.jpg" alt="NEU" style="width:36px;height:36px;object-fit:contain;border-radius:50%;"/>
      </div>
      <div>
        <div class="logo-title">New Era University</div>
        <div class="logo-sub">NEU Library</div>
      </div>
    </div>

    <!-- User info -->
    <div style="padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;gap:10px;">
      <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#FFC107,#E5A800);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#1B2A5E;overflow:hidden;">${avatarHtml}</div>
      <div style="overflow:hidden;min-width:0;">
        <div style="font-size:12px;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:500;">${r.name}</div>
        <div style="font-size:10px;color:rgba(255,255,255,0.4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${r.email}</div>
      </div>
    </div>

    <nav>
      <a class="nav-btn" href="dashboard.html">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Dashboard
      </a>
      <a class="nav-btn" href="stats.html">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>
        Statistics
      </a>
      <a class="nav-btn" href="students.html">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
        Students
      </a>
      <a class="nav-btn" href="entry.html">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 4H3v16h10"/><path d="M13 4l8 4v8l-8 4V4z"/><circle cx="17" cy="12" r="1"/></svg>
        Library Entry
      </a>
      <a class="nav-btn" href="visits.html">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Visit History
      </a>
      <a class="nav-btn" href="users.html">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
        Manage Users
      </a>
      <a class="nav-btn" href="logs.html">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        Activity Logs
      </a>
    </nav>

    <div class="sidebar-footer">
      <button class="nav-btn" onclick="switchToUser()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Switch to User View
      </button>
      <button class="nav-btn" onclick="doLogout()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </button>
    </div>
  </div>`;

  const target = document.getElementById('sidebar-placeholder');
  if (target) target.outerHTML = html;
  setActiveNav();
}

async function switchToUser() {
  const r = await api('switch_role', { body: { role: 'user' } });
  if (r.success) window.location.href = 'welcome.html';
  else alert('Failed: ' + (r.error || 'Unknown error'));
}

function doLogout() {
  api('logout', { body: {} }).then(() => window.location.href = 'index.html');
}

function setActiveNav() {
  const page = window.location.pathname.split('/').pop();
  document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.classList.toggle('active', (btn.getAttribute('href') || '') === page);
  });
}

document.addEventListener('DOMContentLoaded', injectSidebar);
