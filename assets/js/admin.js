/* ============================================================
   ArtSphere — admin.js
   ============================================================ */

const API = '../api';
let token = localStorage.getItem('as_token');
let artPage = 1;
let msgPage = 1;
let deleteTargetId = null;
let editingId = null;

// ── AUTH GUARD ────────────────────────────────────────────
if (!token) window.location.href = 'login.html';

const user = JSON.parse(localStorage.getItem('as_user') || '{}');
if (user.email) document.getElementById('adminEmail').textContent = user.email;

function authHeaders() {
  return { 'Authorization': `Bearer ${token}` };
}

function logout() {
  localStorage.removeItem('as_token');
  localStorage.removeItem('as_user');
  window.location.href = 'login.html';
}

// ── VIEWS ─────────────────────────────────────────────────
function switchView(name, el) {
  document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('view-' + name).classList.add('active');
  document.getElementById('pageTitle').textContent = { dashboard: 'Dashboard', artworks: 'Artworks', messages: 'Messages' }[name];
  document.querySelector(`[data-view="${name}"]`)?.classList.add('active');
  document.getElementById('sidebar').classList.remove('open');

  if (name === 'dashboard') loadDashboard();
  else if (name === 'profile') loadProfile();
  else if (name === 'artworks') loadArtworks();
  else if (name === 'messages') loadMessages();
}

// ── DASHBOARD ─────────────────────────────────────────────
async function loadDashboard() {
  try {
    const res = await fetch(`${API}/stats.php`, { headers: authHeaders() });
    if (res.status === 401) { logout(); return; }
    const d = await res.json();

    document.getElementById('statsGrid').innerHTML = `
      <div class="stat-card"><div class="stat-icon">🖼️</div><div class="stat-label">Total Artworks</div><div class="stat-value">${d.total_artworks}</div></div>
      <div class="stat-card"><div class="stat-icon">✉️</div><div class="stat-label">Total Messages</div><div class="stat-value">${d.total_messages}</div></div>
      <div class="stat-card"><div class="stat-icon">🔔</div><div class="stat-label">Unread</div><div class="stat-value" style="color:var(--orange)">${d.unread_messages}</div></div>
      <div class="stat-card"><div class="stat-icon">🏷️</div><div class="stat-label">Categories</div><div class="stat-value">${d.categories}</div></div>
    `;

    // Update badge
    const badge = document.getElementById('unreadBadge');
    if (d.unread_messages > 0) { badge.textContent = d.unread_messages; badge.style.display = 'inline'; }
    else badge.style.display = 'none';

    // Recent artworks
    const ra = document.getElementById('recentArtworks');
    ra.innerHTML = d.recent_artworks.length ? d.recent_artworks.map(a => `
      <div class="recent-art-card" onclick="switchView('artworks')">
        <img src="../uploads/artworks/${a.image_path}" alt="${escHtml(a.title)}"
             onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22200%22><rect fill=%22%23FDE8DA%22 width=%22200%22 height=%22200%22/><text x=%2250%%22 y=%2250%%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23E8825A%22 font-size=%2232%22>◎</text></svg>'">
        <div class="art-overlay"><span>${escHtml(a.title)}</span></div>
      </div>`).join('') : '<p style="color:var(--ink-light);font-size:.85rem">No artworks yet.</p>';

    // Recent messages
    const rm = document.getElementById('recentMessages');
    rm.innerHTML = d.recent_messages.length ? d.recent_messages.map(m => `
      <div class="msg-preview" onclick="switchView('messages')">
        <div class="msg-preview-name">${escHtml(m.name)}</div>
        <div class="msg-preview-subject">${escHtml(m.subject || 'No subject')}</div>
      </div>`).join('') : '<p style="color:var(--ink-light);font-size:.85rem">No messages yet.</p>';

  } catch (e) { console.error(e); }
}

// ── ARTWORKS ──────────────────────────────────────────────
async function loadArtworks() {
  const grid = document.getElementById('artworkAdminGrid');
  grid.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';
  try {
    const res = await fetch(`${API}/artworks.php?page=${artPage}`, { headers: authHeaders() });
    const data = await res.json();
    if (!data.artworks.length) {
      grid.innerHTML = '<div class="empty-admin" style="grid-column:1/-1"><p>No artworks yet. Upload your first one!</p></div>';
      return;
    }
    grid.innerHTML = data.artworks.map(a => adminArtCard(a)).join('');
    buildPagination('artworkPagination', data.page, data.pages, (p) => { artPage = p; loadArtworks(); });
  } catch (e) { grid.innerHTML = '<p>Failed to load.</p>'; }
}

function adminArtCard(a) {
  const price = a.price > 0 ? `₱${parseFloat(a.price).toLocaleString('en-PH', {minimumFractionDigits: 2})}` : 'Inquire';
  return `
    <div class="admin-art-card">
      <div class="admin-art-img">
        <img src="../uploads/artworks/${a.image_path}" alt="${escHtml(a.title)}"
             onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22300%22 height=%22200%22><rect fill=%22%23FDE8DA%22 width=%22300%22 height=%22200%22/><text x=%2250%%22 y=%2250%%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23E8825A%22 font-size=%2240%22>◎</text></svg>'">
      </div>
      <div class="admin-art-body">
        <div class="admin-art-title">${escHtml(a.title)}</div>
        <div class="admin-art-meta">${escHtml(a.category)} · ${price} · ${a.available ? '✓ Available' : '✗ Sold'}</div>
        <div class="admin-art-actions">
          <button class="btn-edit" onclick="openEditModal(${a.id})">Edit</button>
          <button class="btn-del" onclick="openDeleteModal(${a.id})">Delete</button>
        </div>
      </div>
    </div>`;
}

// ── ARTWORK MODAL ─────────────────────────────────────────
function openArtworkModal() {
  editingId = null;
  document.getElementById('modalTitle').textContent = 'Upload Artwork';
  document.getElementById('artSubmitBtn').textContent = 'Upload';
  document.getElementById('artworkForm').reset();
  document.getElementById('imagePreview').style.display = 'none';
  document.getElementById('uploadPrompt').style.display = 'block';
  document.getElementById('artworkId').value = '';
  document.getElementById('artFeedback').textContent = '';
  document.getElementById('artworkModal').classList.add('open');
}

async function openEditModal(id) {
  editingId = id;
  document.getElementById('modalTitle').textContent = 'Edit Artwork';
  document.getElementById('artSubmitBtn').textContent = 'Save Changes';
  document.getElementById('artFeedback').textContent = '';

  try {
    const res = await fetch(`${API}/artworks.php?id=${id}`);
    const a = await res.json();
    document.getElementById('artworkId').value = a.id;
    document.getElementById('artTitle').value = a.title;
    document.getElementById('artCategory').value = a.category || '';
    document.getElementById('artDesc').value = a.description || '';
    document.getElementById('artPrice').value = a.price;
    document.getElementById('artAvailable').value = a.available;
    // Show existing image
    const preview = document.getElementById('imagePreview');
    preview.src = `../uploads/artworks/${a.image_path}`;
    preview.style.display = 'block';
    document.getElementById('uploadPrompt').style.display = 'none';
    document.getElementById('artworkModal').classList.add('open');
  } catch (e) {
    showToast('Failed to load artwork', 'error');
  }
}

function closeArtworkModal() {
  document.getElementById('artworkModal').classList.remove('open');
  document.getElementById('artworkForm').reset();
}

function previewImage(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = (e) => {
      const preview = document.getElementById('imagePreview');
      preview.src = e.target.result;
      preview.style.display = 'block';
      document.getElementById('uploadPrompt').style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

async function submitArtwork(e) {
  e.preventDefault();
  const btn = document.getElementById('artSubmitBtn');
  const feedback = document.getElementById('artFeedback');
  const id = document.getElementById('artworkId').value;
  btn.disabled = true;
  btn.textContent = 'Saving…';
  feedback.textContent = '';

  const formData = new FormData(e.target);
  if (id) formData.set('_method', 'PUT');

  try {
    const url = id ? `${API}/artworks.php?id=${id}` : `${API}/artworks.php`;
    const method = id ? 'POST' : 'POST'; // PHP reads FormData via POST
    if (id) {
      // For edit: use FormData with PUT-override or a custom param
      const res = await fetch(url, {
        method: 'PUT',
        headers: authHeaders(),
        body: formData
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);
    } else {
      const res = await fetch(url, {
        method: 'POST',
        headers: authHeaders(),
        body: formData
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);
    }

    closeArtworkModal();
    loadArtworks();
    loadDashboard();
    showToast(id ? 'Artwork updated!' : 'Artwork uploaded!', 'success');
  } catch (err) {
    feedback.style.color = '#c0392b';
    feedback.textContent = err.message || 'Failed to save artwork';
  } finally {
    btn.disabled = false;
    btn.textContent = id ? 'Save Changes' : 'Upload';
  }
}

// ── DELETE ────────────────────────────────────────────────
function openDeleteModal(id) {
  deleteTargetId = id;
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
  deleteTargetId = null;
  document.getElementById('deleteModal').classList.remove('open');
}
async function confirmDelete() {
  if (!deleteTargetId) return;
  const btn = document.getElementById('confirmDeleteBtn');
  btn.disabled = true;
  try {
    const res = await fetch(`${API}/artworks.php?id=${deleteTargetId}`, {
      method: 'DELETE', headers: authHeaders()
    });
    if (!res.ok) throw new Error('Delete failed');
    closeDeleteModal();
    loadArtworks();
    loadDashboard();
    showToast('Artwork deleted', 'success');
  } catch (e) {
    showToast('Failed to delete', 'error');
  } finally {
    btn.disabled = false;
  }
}

// ── MESSAGES ──────────────────────────────────────────────
async function loadMessages() {
  const list = document.getElementById('messagesList');
  list.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';
  try {
    const res = await fetch(`${API}/messages.php?page=${msgPage}`, { headers: authHeaders() });
    const data = await res.json();

    // Update badge
    const badge = document.getElementById('unreadBadge');
    if (data.unread > 0) { badge.textContent = data.unread; badge.style.display = 'inline'; }
    else badge.style.display = 'none';

    if (!data.messages.length) {
      list.innerHTML = '<div class="empty-admin"><p>No messages yet.</p></div>';
      return;
    }

    const typeIcon = { commission: '🎨', purchase: '🛒', general: '✉️' };
    list.innerHTML = data.messages.map(m => `
      <div class="msg-row ${m.is_read ? '' : 'unread'}" onclick="openMessage(${m.id})">
        <span class="msg-type-icon">${typeIcon[m.type] || '✉️'}</span>
        <div>
          <div class="msg-row-name">${escHtml(m.name)} <span style="color:var(--ink-light);font-weight:300">· ${escHtml(m.email)}</span></div>
          <div class="msg-row-subject">${escHtml(m.subject || 'No subject')}</div>
        </div>
        <span class="msg-row-date">${timeAgo(m.created_at)}</span>
        <button class="msg-del-btn" onclick="event.stopPropagation();deleteMessage(${m.id})">✕</button>
      </div>`).join('');

    buildPagination('msgPagination', data.page, data.pages, (p) => { msgPage = p; loadMessages(); });
  } catch (e) { list.innerHTML = '<p>Failed to load messages.</p>'; }
}

async function openMessage(id) {
  try {
    const res = await fetch(`${API}/messages.php?page=1`, { headers: authHeaders() });
    const data = await res.json();
    const m = data.messages.find(x => x.id === id);
    if (!m) return;

    // Mark read
    await fetch(`${API}/messages.php?id=${id}`, { method: 'PATCH', headers: authHeaders() });
    document.querySelector(`.msg-row[onclick*="openMessage(${id})"]`)?.classList.remove('unread');

    const typeLabel = { commission: '🎨 Commission Request', purchase: '🛒 Purchase Inquiry', general: '✉️ General Message' }[m.type] || '✉️ Message';
    const detail = document.getElementById('messageDetail');
    detail.innerHTML = `
      <div style="padding:20px 26px">
        <div class="msg-detail-header">
          <div class="msg-detail-name">${escHtml(m.name)}</div>
          <div class="msg-detail-meta">${escHtml(m.email)} · ${typeLabel} · ${new Date(m.created_at).toLocaleString('en-PH')}</div>
        </div>
        ${m.subject ? `<p style="font-weight:500;margin-bottom:12px">${escHtml(m.subject)}</p>` : ''}
        ${m.artwork_title ? `<div class="msg-detail-artwork">Regarding: ${escHtml(m.artwork_title)}</div>` : ''}
        <div class="msg-detail-body">${escHtml(m.message)}</div>
      </div>`;

    document.getElementById('replyBtn').href = `mailto:${m.email}?subject=Re: ${encodeURIComponent(m.subject || 'Your message on ArtSphere')}`;
    document.getElementById('messageModal').classList.add('open');

    // Refresh unread badge
    loadMessages();
  } catch (e) { showToast('Failed to load message', 'error'); }
}

function closeMessageModal() {
  document.getElementById('messageModal').classList.remove('open');
}

async function deleteMessage(id) {
  if (!confirm('Delete this message?')) return;
  try {
    await fetch(`${API}/messages.php?id=${id}`, { method: 'DELETE', headers: authHeaders() });
    loadMessages();
    showToast('Message deleted', 'success');
  } catch (e) { showToast('Failed to delete', 'error'); }
}

// ── PAGINATION ────────────────────────────────────────────
function buildPagination(containerId, current, total, callback) {
  const el = document.getElementById(containerId);
  if (!el || total <= 1) { if(el) el.innerHTML = ''; return; }
  let html = '';
  for (let i = 1; i <= total; i++) {
    html += `<button class="page-btn ${i === current ? 'active' : ''}" onclick="(${callback.toString()})(${i})">${i}</button>`;
  }
  el.innerHTML = html;
}

// ── TOAST ─────────────────────────────────────────────────
function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = `toast ${type} show`;
  setTimeout(() => t.classList.remove('show'), 3000);
}

// ── UTILS ─────────────────────────────────────────────────
function escHtml(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function timeAgo(dateStr) {
  const diff = Date.now() - new Date(dateStr).getTime();
  const m = Math.floor(diff / 60000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.floor(h / 24);
  if (d < 30) return `${d}d ago`;
  return new Date(dateStr).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
}

// ── INIT ──────────────────────────────────────────────────
loadDashboard();

// ── PROFILE ───────────────────────────────────────────────
async function loadProfile() {
  try {
    const res = await fetch(`${API}/profile.php`, { headers: authHeaders() });
    const p   = await res.json();
    if (!p || !p.id) return;
    document.getElementById('profileName').value    = p.name    || '';
    document.getElementById('profileTagline').value = p.tagline || '';
    document.getElementById('profileBio').value     = p.bio     || '';
    if (p.photo) {
      const img = document.getElementById('profilePhotoPreview');
      img.src = `/uploads/profile/${p.photo}`;
      img.style.display = 'block';
      document.getElementById('profilePlaceholder').style.display = 'none';
    }
  } catch (e) { console.error('Profile load error:', e); }
}

function previewProfilePhoto(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = (e) => {
      const img = document.getElementById('profilePhotoPreview');
      img.src = e.target.result;
      img.style.display = 'block';
      document.getElementById('profilePlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

async function saveProfile(e) {
  e.preventDefault();
  const btn      = document.getElementById('profileSaveBtn');
  const feedback = document.getElementById('profileFeedback');
  btn.disabled   = true;
  btn.textContent = 'Saving…';
  feedback.textContent = '';

  const formData = new FormData();
  formData.append('name',    document.getElementById('profileName').value);
  formData.append('tagline', document.getElementById('profileTagline').value);
  formData.append('bio',     document.getElementById('profileBio').value);
  const photoFile = document.getElementById('profilePhotoInput').files[0];
  if (photoFile) formData.append('photo', photoFile);

  try {
    const res = await fetch(`${API}/profile.php`, {
      method: 'POST',
      headers: authHeaders(),
      body: formData
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Failed to save');
    feedback.style.color = '#2a7a4b';
    feedback.textContent = '✓ Profile saved successfully!';
    showToast('Profile updated!', 'success');
  } catch (err) {
    feedback.style.color = '#c0392b';
    feedback.textContent = err.message;
  } finally {
    btn.disabled = false;
    btn.textContent = 'Save Profile';
  }
}

// Extend switchView to handle profile
const _origSwitchView = switchView;
// Patch: load profile when tab is opened
document.addEventListener('DOMContentLoaded', () => {
  // Already called at bottom of file — just load profile here too
});
