/* ============================================================
   ArtSphere — main.js
   ============================================================ */

const API = 'api';
let currentPage = 'home';
let prevPage = 'home';
let galleryPage = 1;
let galleryCategory = 'All';
let searchTimer = null;

// ── INIT ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('year').textContent = new Date().getFullYear();

  // Nav scroll effect
  window.addEventListener('scroll', () => {
    document.getElementById('nav').classList.toggle('scrolled', window.scrollY > 20);
  });

  // Nav toggle mobile
  document.getElementById('navToggle').addEventListener('click', () => {
    document.querySelector('.nav-links').classList.toggle('open');
  });

  // Load featured artworks
  loadFeatured();
});

// ── PAGE ROUTING ──────────────────────────────────────────
function showPage(name) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-' + name).classList.add('active');
  document.querySelectorAll('.nav-links a[data-page]').forEach(a => {
    a.classList.toggle('active', a.dataset.page === name);
  });
  document.querySelector('.nav-links').classList.remove('open');
  window.scrollTo(0, 0);
  prevPage = currentPage;
  currentPage = name;

  if (name === 'gallery') loadGallery();
}

function goBack() {
  showPage(prevPage === 'detail' ? 'gallery' : prevPage);
}

// ── FEATURED ──────────────────────────────────────────────
async function loadFeatured() {
  try {
    const res = await fetch(`${API}/artworks.php?page=1`);
    const data = await res.json();
    const grid = document.getElementById('featuredGrid');
    const items = data.artworks.slice(0, 4);
    if (!items.length) {
      grid.innerHTML = '<p style="color:var(--ink-light);padding:40px;text-align:center;font-style:italic;">No artworks yet.</p>';
      return;
    }
    grid.innerHTML = items.map((a, i) => artworkCard(a, i)).join('');
  } catch (e) {
    console.error(e);
  }
}

// ── GALLERY ───────────────────────────────────────────────
async function loadGallery() {
  const grid = document.getElementById('galleryGrid');
  const search = document.getElementById('gallerySearch').value;
  grid.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

  try {
    const params = new URLSearchParams({ page: galleryPage });
    if (galleryCategory !== 'All') params.set('category', galleryCategory);
    if (search) params.set('search', search);

    const res = await fetch(`${API}/artworks.php?${params}`);
    const data = await res.json();

    // Build category tabs
    buildCategoryTabs(data.categories || []);

    if (!data.artworks.length) {
      grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><h3>No artworks found</h3><p>Try a different search or category.</p></div>';
    } else {
      grid.innerHTML = data.artworks.map((a, i) => artworkCard(a, i)).join('');
    }

    buildPagination('galleryPagination', data.page, data.pages, (p) => {
      galleryPage = p;
      loadGallery();
    });
  } catch (e) {
    grid.innerHTML = '<p style="padding:40px;color:var(--ink-light)">Failed to load gallery.</p>';
  }
}

function buildCategoryTabs(categories) {
  const container = document.getElementById('categoryTabs');
  const all = ['All', ...categories];
  container.innerHTML = all.map(c =>
    `<button class="tab ${c === galleryCategory ? 'active' : ''}"
      onclick="filterCategory('${c}', this)">${c}</button>`
  ).join('');
}

function filterCategory(cat, btn) {
  galleryCategory = cat;
  galleryPage = 1;
  document.querySelectorAll('#categoryTabs .tab').forEach(t => t.classList.remove('active'));
  if (btn) btn.classList.add('active');
  loadGallery();
}

function debounceSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { galleryPage = 1; loadGallery(); }, 400);
}

// ── ARTWORK CARD HTML ─────────────────────────────────────
function artworkCard(a, i = 0) {
  const imgUrl = a.image_path ? `uploads/artworks/${a.image_path}` : '';
  const price = a.price > 0 ? `₱${parseFloat(a.price).toLocaleString('en-PH', {minimumFractionDigits: 2})}` : 'Price on inquiry';
  return `
    <div class="artwork-card" style="animation-delay:${i * 0.06}s" onclick="showArtwork(${a.id})">
      <div class="artwork-card-img">
        <img src="${imgUrl}" alt="${escHtml(a.title)}" loading="lazy"
             onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22><rect fill=%22%23FDE8DA%22 width=%22400%22 height=%22300%22/><text x=%2250%%22 y=%2250%%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23E8825A%22 font-size=%2240%22>◎</text></svg>'">
        <span class="artwork-card-badge ${a.available ? 'badge-available' : 'badge-sold'}">
          ${a.available ? 'Available' : 'Sold'}
        </span>
      </div>
      <div class="artwork-card-body">
        <div class="artwork-card-cat">${escHtml(a.category || 'General')}</div>
        <div class="artwork-card-title">${escHtml(a.title)}</div>
        <div class="artwork-card-price"><strong>${price}</strong></div>
      </div>
    </div>`;
}

// ── ARTWORK DETAIL ────────────────────────────────────────
async function showArtwork(id) {
  showPage('detail');
  const container = document.getElementById('artworkDetail');
  container.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

  try {
    const res = await fetch(`${API}/artworks.php?id=${id}`);
    const a = await res.json();
    const imgUrl = a.image_path ? `uploads/artworks/${a.image_path}` : '';
    const price = a.price > 0 ? `₱${parseFloat(a.price).toLocaleString('en-PH', {minimumFractionDigits: 2})}` : 'Price on inquiry';
    const dateStr = new Date(a.created_at).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });

    container.innerHTML = `
      <div class="detail-img" onclick="openLightbox('${imgUrl}', '${escHtml(a.title)}')">
        <img src="${imgUrl}" alt="${escHtml(a.title)}">
      </div>
      <div class="detail-info">
        <div class="detail-cat">${escHtml(a.category || 'General')}</div>
        <h1 class="detail-title">${escHtml(a.title)}</h1>
        <div class="detail-price">${price}</div>
        <span class="detail-status ${a.available ? 'status-available' : 'status-sold'}">
          ${a.available ? '● Available' : '○ Sold'}
        </span>
        ${a.description ? `<p class="detail-desc">${escHtml(a.description)}</p>` : ''}
        <div class="detail-actions">
          ${a.available ? `
            <button class="btn btn-primary" onclick="inquireArtwork(${a.id}, '${escHtml(a.title)}', 'purchase')">Buy This Piece</button>
            <button class="btn btn-ghost" onclick="inquireArtwork(${a.id}, '${escHtml(a.title)}', 'commission')">Request Similar Commission</button>
          ` : `
            <button class="btn btn-ghost" onclick="inquireArtwork(${a.id}, '${escHtml(a.title)}', 'commission')">Request Similar Commission</button>
          `}
        </div>
        <p class="detail-date">Added ${dateStr}</p>
      </div>`;
  } catch (e) {
    container.innerHTML = '<p>Failed to load artwork.</p>';
  }
}

function inquireArtwork(id, title, type) {
  showPage('contact');
  // Pre-fill form
  setTimeout(() => {
    const form = document.getElementById('contactForm');
    if (form) {
      form.querySelector('[name="subject"]').value = type === 'purchase' ? `Inquiry: ${title}` : `Commission similar to: ${title}`;
      form.querySelector('[name="type"]').value = type;
      form._artworkId = id;
    }
  }, 100);
}

// ── CONTACT FORM ──────────────────────────────────────────
async function submitContact(e) {
  e.preventDefault();
  const form = e.target;
  const btn = document.getElementById('contactBtn');
  const feedback = document.getElementById('contactFeedback');
  feedback.textContent = '';
  btn.disabled = true;
  btn.querySelector('span').textContent = 'Sending…';

  const body = {
    name: form.name.value,
    email: form.email.value,
    subject: form.subject.value || 'New Message from ArtSphere',
    message: form.message.value,
    type: form.type.value,
    artwork_id: form._artworkId || null
  };

  try {
    const res = await fetch(`${API}/messages.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Failed to send');
    feedback.className = 'form-feedback feedback-success';
    feedback.textContent = '✓ Message sent! I'll get back to you soon.';
    form.reset();
    form._artworkId = null;
    showToast('Message sent successfully!', 'success');
  } catch (err) {
    feedback.className = 'form-feedback feedback-error';
    feedback.textContent = err.message;
  } finally {
    btn.disabled = false;
    btn.querySelector('span').textContent = 'Send Message';
  }
}

// ── LIGHTBOX ──────────────────────────────────────────────
function openLightbox(src, alt) {
  const lb = document.getElementById('lightbox');
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightboxImg').alt = alt;
  lb.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.body.style.overflow = '';
}

// ── PAGINATION ────────────────────────────────────────────
function buildPagination(containerId, current, total, callback) {
  const el = document.getElementById(containerId);
  if (total <= 1) { el.innerHTML = ''; return; }
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
