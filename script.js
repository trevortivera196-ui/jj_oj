/* =========================================================
   OJ APARTMENT — Frontend API Client
   All data now flows through PHP /api/ endpoints.
   localStorage is kept only as a session-state cache.
   ========================================================= */

/* ── Base URL (adjust if running under a sub-path) ── */
const API_BASE = './api';

/* ── Fetch wrapper with JSON handling ── */
async function api(endpoint, params = {}, options = {}) {
  const qs  = new URLSearchParams(params).toString();
  const url = `${API_BASE}/${endpoint}${qs ? '?' + qs : ''}`;

  const defaults = {
    method      : 'GET',
    credentials : 'include',          // send session cookie
    headers     : { 'Content-Type': 'application/json' },
  };
  const config = { ...defaults, ...options };

  try {
    const res  = await fetch(url, config);
    const json = await res.json();
    return json;
  } catch (err) {
    console.error('[API error]', endpoint, err);
    return { success: false, message: 'Network error. Please try again.' };
  }
}

async function apiGet(endpoint, params = {}) {
  return api(endpoint, params, { method: 'GET' });
}

async function apiPost(endpoint, body = {}, params = {}) {
  return api(endpoint, params, {
    method : 'POST',
    body   : JSON.stringify(body),
  });
}

/* =========================================================
   AUTH helpers
   ========================================================= */
const PLAN_DURATIONS = { basic: 1, standard: 7, premium: 30 };
const PLAN_LABELS    = { basic: 'Basic Access', standard: 'Standard Access', premium: 'Premium Access' };

/** Called after M-Pesa confirms payment — also stores a local cache */
async function grantAccess(plan, name, email) {
  // PHP side: access_purchases row is created by mpesa callback.
  // We cache locally so the UI can react immediately without another round-trip.
  const expiry = new Date();
  expiry.setDate(expiry.getDate() + (PLAN_DURATIONS[plan] || 7));
  localStorage.setItem('oj_access', JSON.stringify({
    plan, name, email,
    grantedAt : new Date().toISOString(),
    expiresAt : expiry.toISOString(),
  }));
}

/** Check local cache first; if expired/missing, ask server */
async function hasAccess() {
  const raw = localStorage.getItem('oj_access');
  if (raw) {
    try {
      const d = JSON.parse(raw);
      if (new Date(d.expiresAt) > new Date()) return true;
    } catch {}
  }
  // Fallback: ask server for current user's active access
  const res = await apiGet('auth.php', { action: 'me' });
  if (res.success && res.data?.user?.access) {
    const access = res.data.user.access;
    localStorage.setItem('oj_access', JSON.stringify({
      plan       : access.plan,
      name       : res.data.user.name,
      email      : res.data.user.email,
      grantedAt  : access.granted_at,
      expiresAt  : access.expires_at,
    }));
    return true;
  }
  return false;
}

function getAccessData() {
  try { return JSON.parse(localStorage.getItem('oj_access')) || {}; }
  catch { return {}; }
}

async function revokeAccess() {
  await apiPost('auth.php', { action: 'logout' });
  localStorage.removeItem('oj_access');
  localStorage.removeItem('oj_reservations');
  window.location.href = 'index.html';
}

/* ── Register ── */
async function registerUser(name, email, phone, password) {
  return apiPost('auth.php', { action: 'register', name, email, phone, password });
}

/* ── Login ── */
async function loginUser(email, password) {
  const res = await apiPost('auth.php', { action: 'login', email, password });
  if (res.success) {
    const u = res.data.user;
    localStorage.setItem('oj_session_user', JSON.stringify(u));
  }
  return res;
}

/* ── Current user (cached) ── */
function getCachedUser() {
  try { return JSON.parse(localStorage.getItem('oj_session_user')); }
  catch { return null; }
}

/* =========================================================
   TOAST
   ========================================================= */
function showToast(message, type = 'success') {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.textContent = message;
  toast.className   = 'toast' + (type === 'error' ? ' error' : '');
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 3500);
}

/* =========================================================
   APARTMENTS PAGE
   ========================================================= */
let currentAptId = null;

async function initApartmentsPage() {
  if (!document.getElementById('apartments-grid')) return;

  const access = await hasAccess();

  if (!access) {
    document.getElementById('access-gate').style.display = 'flex';
    document.getElementById('main-content').style.display = 'none';
    const signOutBtn = document.querySelector('[onclick="revokeAccess()"]');
    if (signOutBtn) signOutBtn.style.display = 'none';
    return;
  }

  document.getElementById('access-gate').style.display = 'none';
  document.getElementById('main-content').style.display = 'block';

  const accessData = getAccessData();

  const greeting = document.getElementById('user-greeting');
  if (greeting && accessData.name) {
    greeting.textContent = 'Hi, ' + accessData.name.split(' ')[0];
  }

  const planLabel = document.getElementById('access-plan-label');
  if (planLabel) planLabel.textContent = PLAN_LABELS[accessData.plan] || 'Standard Access';

  const expiryEl = document.getElementById('access-expiry');
  if (expiryEl && accessData.expiresAt) {
    expiryEl.textContent = new Date(accessData.expiresAt)
      .toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
  }

  await fetchAndRenderApartments();
}

async function fetchAndRenderApartments(filters = {}) {
  const res = await apiGet('apartments.php', { action: 'list', ...filters });
  if (!res.success) {
    showToast('Failed to load apartments: ' + res.message, 'error');
    return;
  }
  renderApartments(res.data.apartments || []);
}

async function filterApts() {
  const type     = document.getElementById('filter-type')?.value   || '';
  const status   = document.getElementById('filter-status')?.value || '';
  const floor    = document.getElementById('filter-floor')?.value  || '';
  const maxRent  = document.getElementById('filter-price')?.value  || '';

  await fetchAndRenderApartments({
    type,
    status,
    floor     : floor  || undefined,
    max_rent  : maxRent || undefined,
  });
}

function renderApartments(list) {
  const grid = document.getElementById('apartments-grid');
  if (!grid) return;

  grid.innerHTML = '';

  if (!list.length) {
    grid.innerHTML = `
      <div style="grid-column:1/-1;text-align:center;padding:4rem;color:var(--text-muted);">
        <div style="font-size:3rem;margin-bottom:1rem;">🔍</div>
        <h3 style="margin-bottom:0.5rem;">No units match your filters</h3>
        <p>Try adjusting your filters to see more results.</p>
      </div>`;
    const countEl = document.getElementById('filter-count');
    if (countEl) countEl.textContent = '0 units shown';
    return;
  }

  list.forEach(apt => {
    const badgeClass = apt.status === 'available' ? 'available'
                     : apt.status === 'reserved'  ? 'reserved' : 'occupied';
    const badgeText  = apt.status.charAt(0).toUpperCase() + apt.status.slice(1);
    const amenities  = Array.isArray(apt.amenities) ? apt.amenities : [];

    const card = document.createElement('div');
    card.className = 'apt-card';
    card.innerHTML = `
      <div class="apt-image">
        <img src="${apt.image_url || ''}" alt="Unit ${apt.unit}" loading="lazy" />
        <span class="apt-badge ${badgeClass}">${badgeText}</span>
        <span class="apt-floor-badge">Floor ${apt.floor}</span>
      </div>
      <div class="apt-body">
        <div class="apt-title">Unit ${apt.unit}</div>
        <div class="apt-type">${apt.type}</div>
        <div class="apt-meta">
          ${apt.beds > 0 ? `<div class="apt-meta-item"><span class="meta-icon">🛏️</span>${apt.beds} Bed${apt.beds > 1 ? 's' : ''}</div>` : ''}
          <div class="apt-meta-item"><span class="meta-icon">🚿</span>${apt.baths} Bath${apt.baths > 1 ? 's' : ''}</div>
          <div class="apt-meta-item"><span class="meta-icon">📐</span>${apt.size_sqm} m²</div>
          <div class="apt-meta-item"><span class="meta-icon">🏢</span>Floor ${apt.floor}</div>
        </div>
        <div class="apt-price-row">
          <div class="apt-price">KES ${Number(apt.rent).toLocaleString()}<span>/mo</span></div>
          <button class="btn btn-gold" style="padding:.55rem 1.25rem;font-size:.85rem;"
            onclick="openModal(${apt.id})">View Details</button>
        </div>
      </div>`;
    grid.appendChild(card);
  });

  const avail   = list.filter(a => a.status === 'available').length;
  const countEl = document.getElementById('filter-count');
  if (countEl) countEl.textContent = `${list.length} unit${list.length !== 1 ? 's' : ''} shown · ${avail} available`;
}

/* ── Detail modal ── */
async function openModal(aptId) {
  currentAptId = aptId;
  document.getElementById('modal-body').innerHTML = '<p style="padding:2rem;text-align:center;color:var(--text-muted);">Loading…</p>';
  document.getElementById('apt-modal').classList.add('open');
  document.body.style.overflow = 'hidden';

  const res = await apiGet('apartments.php', { action: 'get', id: aptId });
  if (!res.success) {
    document.getElementById('modal-body').innerHTML = `<p style="color:var(--danger);padding:2rem;">${res.message}</p>`;
    return;
  }

  const apt       = res.data.apartment;
  const canReserve = apt.status === 'available';
  const amenities  = Array.isArray(apt.amenities) ? apt.amenities : [];

  document.getElementById('modal-body').innerHTML = `
    <img src="${apt.image_url}" alt="Unit ${apt.unit}"
      style="width:100%;height:220px;object-fit:cover;border-radius:12px;margin-bottom:1.5rem;" />
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem;">
      <h2>Unit ${apt.unit} — ${apt.type}</h2>
      <span class="apt-badge ${apt.status}" style="position:static;">${apt.status.charAt(0).toUpperCase()+apt.status.slice(1)}</span>
    </div>
    <p class="modal-sub">Floor ${apt.floor} &nbsp;·&nbsp; ${apt.size_sqm} m² &nbsp;·&nbsp; OJ Apartment</p>
    <div class="modal-detail-row">
      ${apt.beds > 0 ? `<div class="modal-detail"><div class="d-label">Bedrooms</div><div class="d-value">🛏️ ${apt.beds}</div></div>` : ''}
      <div class="modal-detail"><div class="d-label">Bathrooms</div><div class="d-value">🚿 ${apt.baths}</div></div>
      <div class="modal-detail"><div class="d-label">Size</div><div class="d-value">📐 ${apt.size_sqm} m²</div></div>
      <div class="modal-detail"><div class="d-label">Floor</div><div class="d-value">🏢 ${apt.floor}</div></div>
    </div>
    <p style="color:var(--text-muted);font-size:.9rem;margin:1rem 0;">${apt.description || ''}</p>
    <div style="font-size:.78rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:.75rem;">Amenities</div>
    <div class="amenities-list">
      ${amenities.map(a => `<span class="amenity-tag">✓ ${a}</span>`).join('')}
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--dark-border);padding-top:1.25rem;margin-top:.5rem;">
      <div>
        <div style="font-size:2rem;font-weight:800;color:var(--gold);">KES ${Number(apt.rent).toLocaleString()}
          <span style="font-size:.85rem;font-weight:400;color:var(--text-muted);">/mo</span></div>
        <div style="font-size:.78rem;color:var(--text-muted);">Excluding service charge</div>
      </div>
      ${canReserve
        ? `<button class="btn btn-gold" onclick="openReserveModal(${apt.id})">Reserve This Unit</button>`
        : `<span style="color:var(--text-muted);font-size:.88rem;">Not available for reservation</span>`}
    </div>`;
}

function closeModal() {
  document.getElementById('apt-modal').classList.remove('open');
  document.body.style.overflow = '';
}

/* ── Reserve modal ── */
function openReserveModal(aptId) {
  currentAptId = aptId;
  const desc = document.getElementById('reserve-modal-desc');
  if (desc) desc.textContent = `You are about to reserve Unit ${aptId}. Please confirm your details below.`;
  closeModal();
  document.getElementById('reserve-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeReserveModal() {
  document.getElementById('reserve-modal').classList.remove('open');
  document.body.style.overflow = '';
}

async function confirmReservation() {
  if (!currentAptId) return;
  const user     = getCachedUser();
  const date     = document.getElementById('movein-date')?.value || '';
  const notes    = document.getElementById('reserve-notes')?.value || '';

  if (!user) {
    showToast('Please log in to reserve a unit.', 'error');
    closeReserveModal();
    return;
  }

  const res = await apiPost('apartments.php',
    { action: 'reserve', apartment_id: currentAptId, move_in_date: date, notes },
    {}
  );

  if (!res.success) {
    showToast(res.message || 'Reservation failed.', 'error');
    return;
  }

  closeReserveModal();
  showToast('✅ Unit reserved successfully! We\'ll contact you soon.');
  await fetchAndRenderApartments();
}

/* Close on overlay click */
document.addEventListener('DOMContentLoaded', () => {
  ['apt-modal','reserve-modal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', e => { if (e.target === el) { id === 'apt-modal' ? closeModal() : closeReserveModal(); } });
  });
});

/* =========================================================
   PAYMENT PAGE  (payment.html)
   ========================================================= */

/** Called by payment.html after simulated/real M-Pesa confirmation */
async function handlePaymentSuccess(plan, name, email, phone) {
  // 1. Cache access locally for immediate UI use
  await grantAccess(plan, name, email);

  // 2. If user is logged in, the server already created access_purchases
  //    via the M-Pesa callback. If not, try to create a session user first.
  const sessionRes = await apiGet('auth.php', { action: 'me' });
  if (!sessionRes.success) {
    // Not logged in — create a lightweight account automatically
    const tempPass = 'Oj' + Math.random().toString(36).slice(-8) + '!';
    const regRes   = await registerUser(name, email, phone, tempPass);
    if (regRes.success) {
      await loginUser(email, tempPass);
    }
  }
}

/* =========================================================
   CONTACT FORM  (index.html)
   ========================================================= */
async function submitContact() {
  const name    = document.getElementById('cf-name')?.value.trim()    || '';
  const contact = document.getElementById('cf-contact')?.value.trim() || '';
  const interest= document.getElementById('cf-interest')?.value        || 'General information';
  const message = document.getElementById('cf-message')?.value.trim() || '';

  if (!name)    { showToast('Please enter your name.',              'error'); return; }
  if (!contact) { showToast('Please enter your email or phone.',    'error'); return; }
  if (!message) { showToast('Please enter a message.',              'error'); return; }

  const btn = document.querySelector('[onclick="submitContact()"]');
  if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }

  const res = await apiPost('contact.php', { action:'submit', name, contact, interest, message });

  if (btn) { btn.disabled = false; btn.textContent = 'Send Message →'; }

  if (res.success) {
    showToast('✅ Message sent! We\'ll get back to you shortly.');
    ['cf-name','cf-contact','cf-message'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
  } else {
    showToast(res.message || 'Failed to send message.', 'error');
  }
}

/* =========================================================
   PAYROLL PAGE  (payroll.html)
   Overrides payroll.js localStorage calls with API calls
   ========================================================= */

/* These functions shadow the same names in payroll.js.
   Load script.js BEFORE payroll.js so payroll.js can still
   use calcNetPay etc., but data now comes from the server. */

async function fetchStaffFromAPI() {
  const res = await apiGet('payroll.php', { action: 'staff_list' });
  return res.success ? (res.data.staff || []) : [];
}

async function saveStaffToAPI(body) {
  return apiPost('payroll.php', body);
}

async function deleteStaffFromAPI(id) {
  return apiPost('payroll.php', { action: 'staff_delete', id });
}

async function fetchHistoryFromAPI(type = '', month = '') {
  return apiGet('payroll.php', { action: 'history', type, month });
}

async function fetchSettingsFromAPI() {
  const res = await apiGet('payroll.php', { action: 'settings_get' });
  return res.success ? res.data.settings : {};
}

async function saveSettingsToAPI(settings) {
  return apiPost('payroll.php', { action: 'settings_save', ...settings });
}

async function runPayrollAPI(staffIds = []) {
  return apiPost('payroll.php', {
    action    : 'run',
    month     : new Date().toISOString().slice(0, 7),
    staff_ids : staffIds,
  });
}

async function sendWithdrawalAPI(staffId, phone, amount, reason) {
  // Step 1: log withdrawal in DB
  const logRes = await apiPost('payroll.php', {
    action: 'withdrawal', staff_id: staffId, phone, amount, reason,
  });
  if (!logRes.success) return logRes;

  // Step 2: trigger M-Pesa B2C
  return apiPost('mpesa.php', {
    action   : 'b2c_send',
    phone,
    amount,
    staff_id : staffId,
    reason,
  });
}

/* STK Push trigger for payment.html */
async function triggerStkPush(phone, amount, plan, userId = 0) {
  const kes = { '5': 650, '15': 1950, '30': 3900 };
  const kesAmount = kes[String(amount)] || Math.round(amount * 130);
  const ref = 'OJ-' + plan.slice(0,3).toUpperCase() + '-' + Date.now().toString().slice(-6);

  return apiPost('mpesa.php', {
    action  : 'stk_push',
    phone,
    amount  : kesAmount,
    ref,
    desc    : `OJ Apartment ${plan} access`,
    user_id : userId,
  });
}

/* Poll transaction status (used in payment.html after STK push) */
async function pollStkStatus(checkoutRequestId, maxTries = 20, intervalMs = 3000) {
  return new Promise((resolve) => {
    let tries = 0;
    const timer = setInterval(async () => {
      tries++;
      const res = await apiGet('mpesa.php', { action: 'status', checkout_request_id: checkoutRequestId });
      if (res.success) {
        const status = res.data?.transaction?.status;
        if (status === 'success') { clearInterval(timer); resolve({ success: true,  status }); }
        if (status === 'failed')  { clearInterval(timer); resolve({ success: false, status }); }
      }
      if (tries >= maxTries)      { clearInterval(timer); resolve({ success: false, status: 'timeout' }); }
    }, intervalMs);
  });
}


/* =========================================================
   MANAGE API — Extended property management endpoints
   (manage.php)
   ========================================================= */

/* ── Password reset ── */
async function forgotPassword(email) {
  return apiPost('manage.php', { action: 'forgot_password', email });
}
async function resetPassword(token, newPassword) {
  return apiPost('manage.php', { action: 'reset_password', token, new_password: newPassword });
}

/* ── Leases ── */
async function getMyLease() {
  return apiGet('manage.php', { action: 'my_lease' });
}
async function listLeases(status = '') {
  return apiGet('manage.php', { action: 'lease_list', status });
}
async function createLease(data) {
  return apiPost('manage.php', { action: 'lease_create', ...data });
}
async function updateLease(data) {
  return apiPost('manage.php', { action: 'lease_update', ...data });
}

/* ── Rent payments ── */
async function listRentPayments(month = '', status = '') {
  return apiGet('manage.php', { action: 'rent_list', month, status });
}
async function payRent(leaseId, periodMonth, mpesaReceipt, mpesaPhone) {
  return apiPost('manage.php', {
    action: 'rent_pay', lease_id: leaseId,
    period_month: periodMonth, mpesa_receipt: mpesaReceipt, mpesa_phone: mpesaPhone,
  });
}

/* ── Maintenance requests ── */
async function listMaintenanceRequests(status = '', priority = '') {
  return apiGet('manage.php', { action: 'maint_list', status, priority });
}
async function createMaintenanceRequest(data) {
  return apiPost('manage.php', { action: 'maint_create', ...data });
}
async function updateMaintenanceRequest(data) {
  return apiPost('manage.php', { action: 'maint_update', ...data });
}

/* ── Utility bills ── */
async function listUtilityBills(month = '', type = '') {
  return apiGet('manage.php', { action: 'utility_list', month, type });
}
async function payUtilityBill(id, mpesaReceipt) {
  return apiPost('manage.php', { action: 'utility_pay', id, mpesa_receipt: mpesaReceipt });
}

/* ── Announcements ── */
async function getAnnouncements() {
  return apiGet('manage.php', { action: 'announcements' });
}
async function createAnnouncement(data) {
  return apiPost('manage.php', { action: 'announcement_create', ...data });
}

/* ── Visitor log ── */
async function visitorCheckIn(data) {
  return apiPost('manage.php', { action: 'visitor_log_in', ...data });
}
async function visitorCheckOut(id) {
  return apiPost('manage.php', { action: 'visitor_log_out', id });
}
async function listVisitors(apartmentId = 0, date = '') {
  return apiGet('manage.php', { action: 'visitor_list', apartment_id: apartmentId, date });
}

/* ── Apartment images ── */
async function listApartmentImages(apartmentId) {
  return apiGet('manage.php', { action: 'images_list', apartment_id: apartmentId });
}
async function addApartmentImage(data) {
  return apiPost('manage.php', { action: 'image_add', ...data });
}

/* ── Notifications ── */
async function getNotifications(limit = 20) {
  return apiGet('manage.php', { action: 'notifications', limit });
}
async function markNotificationsRead() {
  return apiPost('manage.php', { action: 'notifications_read' });
}

/* ── Dashboard summary ── */
async function getDashboardSummary() {
  return apiGet('manage.php', { action: 'dashboard' });
}

/* ── Audit log ── */
async function getAuditLog(limit = 50, offset = 0) {
  return apiGet('manage.php', { action: 'audit_log', limit, offset });
}


/* =========================================================
   HOMEPAGE / SITE DATA — new tables (manage.php)
   ========================================================= */

/* ── Homepage live stats (hero counters) ── */
async function loadHomepageStats() {
  const res = await apiGet('manage.php', { action: 'homepage_stats' });
  if (!res.success) return;
  const s = res.data.stats;
  // Animate counters if elements exist
  const map = {
    'cnt-units':  parseInt(s.total_units)     || 48,
    'cnt-avail':  parseInt(s.available_units) || 12,
    'cnt-floors': parseInt(s.total_floors)    || 8,
  };
  Object.entries(map).forEach(([id, target]) => {
    const el = document.getElementById(id);
    if (!el) return;
    let n = 0;
    const step = Math.ceil(target / 40);
    const t = setInterval(() => {
      n = Math.min(n + step, target);
      el.textContent = n;
      if (n >= target) clearInterval(t);
    }, 35);
  });
}

/* ── Testimonials ── */
async function getTestimonials() {
  return apiGet('manage.php', { action: 'testimonials' });
}
async function saveTestimonial(data) {
  return apiPost('manage.php', { action: 'testimonial_save', ...data });
}
async function deleteTestimonial(id) {
  return apiPost('manage.php', { action: 'testimonial_delete', id });
}

/* ── Site Gallery ── */
async function getSiteGallery(category = '') {
  return apiGet('manage.php', { action: 'gallery', category });
}
async function saveGalleryImage(data) {
  return apiPost('manage.php', { action: 'gallery_save', ...data });
}
async function deleteGalleryImage(id) {
  return apiPost('manage.php', { action: 'gallery_delete', id });
}

/* ── Access Plans / Pricing ── */
async function getAccessPlans() {
  return apiGet('manage.php', { action: 'plans' });
}
async function updateAccessPlan(data) {
  return apiPost('manage.php', { action: 'plan_update', ...data });
}

/* Load pricing dynamically into the homepage cards */
async function loadPricingCards() {
  const res = await getAccessPlans();
  if (!res.success) return;
  res.data.plans.forEach(plan => {
    const priceEl = document.querySelector(`[data-plan="${plan.plan_key}"] .price`);
    const btnEl   = document.querySelector(`[data-plan="${plan.plan_key}"] .btn`);
    if (priceEl) priceEl.innerHTML = `<sup>KES</sup>${Number(plan.price_kes).toLocaleString()}`;
    if (btnEl)   btnEl.href = `payment.html?plan=${plan.plan_key}&amount=${plan.price_usd}`;
  });
}

/* ── Site Settings ── */
async function getSiteSettings(group = '') {
  return apiGet('manage.php', { action: 'site_settings', group });
}
async function saveSiteSettings(settingsObj) {
  return apiPost('manage.php', { action: 'site_settings_save', ...settingsObj });
}

/* Apply contact settings to the homepage contact section */
async function loadContactInfo() {
  const res = await getSiteSettings('contact');
  if (!res.success) return;
  const s = res.data.settings;
  const map = {
    '[data-setting="contact_address"]' : s.contact_address,
    '[data-setting="contact_phone"]'   : s.contact_phone,
    '[data-setting="contact_email"]'   : s.contact_email,
    '[data-setting="office_hours"]'    : s.office_hours,
  };
  Object.entries(map).forEach(([sel, val]) => {
    document.querySelectorAll(sel).forEach(el => { if (val) el.textContent = val; });
  });
}

/* ── Social Links ── */
async function getSocialLinks() {
  return apiGet('manage.php', { action: 'social_links' });
}
async function saveSocialLink(data) {
  return apiPost('manage.php', { action: 'social_link_save', ...data });
}

/* ── Floor Plans ── */
async function getFloorPlans() {
  return apiGet('manage.php', { action: 'floor_plans' });
}

/* ── Amenities ── */
async function getAmenities() {
  return apiGet('manage.php', { action: 'amenities' });
}
async function saveAmenity(data) {
  return apiPost('manage.php', { action: 'amenity_save', ...data });
}

/* ── FAQs ── */
async function getFaqs(category = '') {
  return apiGet('manage.php', { action: 'faqs', category });
}
async function saveFaq(data) {
  return apiPost('manage.php', { action: 'faq_save', ...data });
}
async function deleteFaq(id) {
  return apiPost('manage.php', { action: 'faq_delete', id });
}

/* ── News / Blog ── */
async function getNewsPosts(limit = 10, category = '') {
  return apiGet('manage.php', { action: 'news_list', limit, category });
}
async function getNewsPost(slug) {
  return apiGet('manage.php', { action: 'news_get', slug });
}
async function saveNewsPost(data) {
  return apiPost('manage.php', { action: 'news_save', ...data });
}
async function deleteNewsPost(id) {
  return apiPost('manage.php', { action: 'news_delete', id });
}

/* ── Team Members ── */
async function getTeam() {
  return apiGet('manage.php', { action: 'team' });
}
async function saveTeamMember(data) {
  return apiPost('manage.php', { action: 'team_save', ...data });
}

/* ── Unit Type Features ── */
async function getUnitFeatures(type = '') {
  return apiGet('manage.php', { action: 'unit_features', type });
}

/* ─────────────────────────────────────────────────────────
   AUTO-INIT on index.html
   Loads live data into the homepage sections if running
   against a PHP backend (falls back gracefully if not).
   ───────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  // Only run on index.html (detect by presence of hero section)
  if (!document.getElementById('hero')) return;

  // Load live hero stats
  loadHomepageStats().catch(() => {});

  // Load contact info into data-setting elements (if any)
  loadContactInfo().catch(() => {});
});
