<?php
/* =========================================================
   OJ APARTMENT — M-Pesa Payment Page (PHP)
   - Loads plan details from access_plans table
   - Generates a unique transaction reference server-side
   - Handles STK Push via api/mpesa.php (AJAX)
   - On callback: access_purchases row created by mpesa.php
   ========================================================= */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

startSession();

/* ── Resolve plan from URL ── */
$planKey  = sanitize($_GET['plan']   ?? 'standard');
$amtParam = (float)($_GET['amount']  ?? 0);  // USD hint (ignored if DB has plan)

$plan = DB::queryOne(
    'SELECT * FROM access_plans WHERE plan_key = ? AND is_active = 1',
    [$planKey]
);

/* Fallback if DB unavailable or bad key */
if (!$plan) {
    $fallbacks = [
        'basic'    => ['plan_key'=>'basic',    'plan_name'=>'Basic Access',    'price_kes'=>650,   'price_usd'=>5,  'duration_days'=>1,  'features'=>'["View available units","24-hour access window"]'],
        'standard' => ['plan_key'=>'standard', 'plan_name'=>'Standard Access', 'price_kes'=>1950,  'price_usd'=>15, 'duration_days'=>7,  'features'=>'["Everything in Basic","Reserve a unit online","7-day access window"]'],
        'premium'  => ['plan_key'=>'premium',  'plan_name'=>'Premium Access',  'price_kes'=>3900,  'price_usd'=>30, 'duration_days'=>30, 'features'=>'["Everything in Standard","Virtual tours","30-day access window"]'],
    ];
    $plan = $fallbacks[$planKey] ?? $fallbacks['standard'];
}

$plan['features'] = is_string($plan['features'])
    ? json_decode($plan['features'], true)
    : ($plan['features'] ?? []);

/* ── Generate a unique transaction reference ── */
$txRef = 'OJ-' . strtoupper(substr($plan['plan_key'], 0, 3)) . '-'
       . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

/* ── Current logged-in user (if any) ── */
$user = currentUser();

/* ── Site settings for contact info ── */
$siteName = 'OJ Apartment';
try {
    $ss = DB::query('SELECT setting_key,setting_value FROM site_settings WHERE setting_group="general"');
    foreach ($ss as $r) { if ($r['setting_key'] === 'site_name') $siteName = $r['setting_value']; }
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Pay via M-Pesa — <?= htmlspecialchars($siteName) ?></title>
  <link rel="stylesheet" href="styles.css" />
  <style>
    /* Nav */
    .nav-toggle{display:none;flex-direction:column;justify-content:center;gap:5px;background:none;border:none;cursor:pointer;padding:4px;z-index:201;}
    .nav-toggle span{display:block;width:26px;height:2.5px;background:var(--text);border-radius:2px;transition:transform .3s,opacity .3s;}
    .nav-toggle.open span:nth-child(1){transform:translateY(7.5px) rotate(45deg);}
    .nav-toggle.open span:nth-child(2){opacity:0;transform:scaleX(0);}
    .nav-toggle.open span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg);}
    .nav-drawer{position:fixed;inset:0;z-index:200;pointer-events:none;}
    .nav-drawer-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.75);opacity:0;transition:opacity .3s;backdrop-filter:blur(6px);}
    .nav-drawer-panel{position:absolute;top:0;right:0;width:min(320px,85vw);height:100%;background:var(--dark-card);border-left:1px solid var(--dark-border);transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;padding:5.5rem 2rem 2rem;}
    .nav-drawer.open{pointer-events:all;}
    .nav-drawer.open .nav-drawer-backdrop{opacity:1;}
    .nav-drawer.open .nav-drawer-panel{transform:translateX(0);}
    .nav-drawer-panel a{display:flex;align-items:center;gap:.75rem;padding:1rem 0;border-bottom:1px solid var(--dark-border);font-size:1rem;color:var(--text);transition:color .2s;}
    .nav-drawer-panel a:hover{color:var(--gold);}
    .nav-drawer-panel a .nav-icon{font-size:1.2rem;width:24px;text-align:center;}
    .nav-drawer-logo{font-size:1.1rem;font-weight:700;color:var(--gold);letter-spacing:2px;text-transform:uppercase;margin-bottom:.25rem;}
    .nav-drawer-tagline{font-size:.78rem;color:var(--text-muted);margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid var(--dark-border);}
    @media(max-width:768px){.navbar .nav-links{display:none;}.nav-toggle{display:flex;}}

    /* Page */
    .payment-page{min-height:calc(100vh - 66px);display:flex;align-items:center;justify-content:center;padding:3rem 2rem;background:radial-gradient(ellipse 80% 60% at 50% 0%,rgba(201,168,76,.06),transparent),var(--dark);}
    .payment-box{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:24px;padding:3rem 2.5rem;width:100%;max-width:490px;box-shadow:0 8px 32px rgba(0,0,0,.5);}
    .back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--text-muted);margin-bottom:2rem;transition:color .2s;}
    .back-link:hover{color:var(--gold);}
    .divider{border:none;border-top:1px solid var(--dark-border);margin:1.5rem 0;}
    .section-label{font-size:.75rem;letter-spacing:2px;text-transform:uppercase;color:var(--text-muted);margin-bottom:1rem;font-weight:600;}

    /* M-Pesa brand */
    .mpesa-brand{display:flex;align-items:center;gap:1rem;background:linear-gradient(135deg,#006633 0%,#00a650 100%);border-radius:14px;padding:1.25rem 1.5rem;margin-bottom:2rem;}
    .mpesa-logo-circle{width:56px;height:56px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;font-size:1.7rem;flex-shrink:0;}
    .mpesa-brand-text .brand-name{font-size:1.3rem;font-weight:800;color:#fff;letter-spacing:1px;}
    .mpesa-brand-text .brand-sub{font-size:.8rem;color:rgba(255,255,255,.75);}

    /* Plan summary */
    .plan-summary{background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.25);border-radius:10px;padding:1rem 1.25rem;margin-bottom:2rem;display:flex;justify-content:space-between;align-items:center;}
    .plan-label{color:var(--text-muted);font-size:.82rem;}
    .plan-amount{font-size:1.35rem;font-weight:700;color:var(--gold);}
    .plan-features{margin-top:.75rem;padding-top:.75rem;border-top:1px solid rgba(201,168,76,.15);display:flex;flex-direction:column;gap:.3rem;}
    .plan-feature{font-size:.8rem;color:var(--text-muted);display:flex;align-items:center;gap:.5rem;}
    .plan-feature .pf-check{color:var(--success);}

    /* Inputs */
    .form-group{margin-bottom:1.2rem;}
    .form-group label{display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.5px;}
    .form-group input{width:100%;background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:8px;padding:.75rem 1rem;color:var(--text);font-size:.92rem;outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit;}
    .form-group input:focus{border-color:#00a650;box-shadow:0 0 0 3px rgba(0,166,80,.12);}
    .form-group input::placeholder{color:var(--text-faint);}

    /* Phone prefix */
    .phone-wrap{display:flex;align-items:stretch;background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:8px;overflow:hidden;transition:border-color .2s,box-shadow .2s;}
    .phone-wrap:focus-within{border-color:#00a650;box-shadow:0 0 0 3px rgba(0,166,80,.12);}
    .phone-prefix{display:flex;align-items:center;gap:.4rem;padding:.75rem 1rem;background:rgba(0,166,80,.1);border-right:1px solid var(--dark-border);font-size:.9rem;color:var(--text);white-space:nowrap;flex-shrink:0;}
    .phone-wrap input{flex:1;background:none;border:none;padding:.75rem 1rem;color:var(--text);font-size:.95rem;outline:none;font-family:inherit;}
    .phone-wrap input::placeholder{color:var(--text-faint);}

    /* STK Push steps */
    #stk-panel{display:none;}
    .stk-steps{display:flex;flex-direction:column;gap:.65rem;margin:1.5rem 0;}
    .stk-step{display:flex;align-items:center;gap:.9rem;background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:10px;padding:.9rem 1rem;font-size:.88rem;opacity:.35;transition:opacity .4s,border-color .4s;}
    .stk-step.done{opacity:1;border-color:#00a650;}
    .stk-step.active{opacity:1;border-color:var(--gold);}
    .stk-step .si{font-size:1.2rem;width:26px;text-align:center;flex-shrink:0;}
    .stk-step .st{flex:1;}
    .stk-step .st strong{display:block;margin-bottom:.1rem;}
    .stk-step .st span{font-size:.78rem;color:var(--text-muted);}
    .stk-spinner{width:17px;height:17px;border:2.5px solid var(--dark-border);border-top-color:var(--gold);border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0;margin-left:auto;}
    .stk-tick{color:#00a650;font-size:1.05rem;margin-left:auto;}
    @keyframes spin{to{transform:rotate(360deg)}}
    .phone-pill{display:inline-flex;align-items:center;gap:.5rem;background:rgba(0,166,80,.1);border:1px solid rgba(0,166,80,.3);border-radius:50px;padding:.35rem .9rem;font-size:.85rem;color:#fff;margin:.25rem 0 1.25rem;}
    .stk-actions{display:flex;gap:1rem;flex-wrap:wrap;margin-top:.25rem;}
    .stk-actions button{background:none;border:none;font-size:.85rem;cursor:pointer;padding:0;}
    .btn-resend{color:var(--gold);}
    .btn-cancel-stk{color:var(--text-muted);}

    /* USSD fallback */
    .ussd-box{background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:10px;padding:1.1rem 1.25rem;font-size:.88rem;line-height:2;}
    .ussd-code{font-family:'Courier New',monospace;background:rgba(0,166,80,.15);border:1px solid rgba(0,166,80,.3);color:#4dff9a;border-radius:6px;padding:.2rem .55rem;font-size:.9rem;}

    /* Success overlay */
    #success-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:300;align-items:center;justify-content:center;flex-direction:column;text-align:center;padding:2rem;}
    #success-overlay.show{display:flex;}
    .success-box{background:var(--dark-card);border:1px solid rgba(0,166,80,.4);border-radius:20px;padding:3rem 2.5rem;max-width:420px;width:100%;}
    .success-box .si{font-size:4rem;margin-bottom:1rem;}
    .success-box h2{font-size:1.6rem;margin-bottom:.5rem;color:#00a650;}
    .success-box p{color:var(--text-muted);margin-bottom:2rem;font-size:.95rem;}
    .progress-bar{height:4px;background:var(--dark-border);border-radius:2px;overflow:hidden;margin-bottom:.75rem;}
    .progress-fill{height:100%;background:#00a650;width:0%;transition:width 3s linear;}
    .redirect-note{font-size:.8rem;color:var(--text-muted);}

    /* Toast */
    .toast{position:fixed;bottom:2rem;right:2rem;background:var(--dark-card);border:1px solid var(--dark-border);border-left:4px solid var(--success);border-radius:var(--radius);padding:.9rem 1.4rem;font-size:.88rem;box-shadow:var(--shadow);z-index:600;transform:translateY(120px);opacity:0;transition:transform .35s cubic-bezier(.34,1.4,.64,1),opacity .3s;max-width:340px;}
    .toast.show{transform:translateY(0);opacity:1;}
    .toast.error{border-left-color:var(--danger);}
    .secure-note{display:flex;align-items:center;justify-content:center;gap:.4rem;font-size:.78rem;color:var(--text-faint);margin-top:1.25rem;}

    @media(max-width:500px){.payment-box{padding:2rem 1.25rem;border-radius:16px;}}
  </style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="navbar">
  <div class="logo">OJ <span>Apartment</span></div>
  <div class="nav-links">
    <a href="index.php">Home</a>
    <a href="index.php#about">About</a>
    <a href="index.php#pricing">Access Plans</a>
    <a href="apartments.php">Vacancies</a>
    <a href="payroll.php">💼 Payroll</a>
  </div>
  <button class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
</nav>
<div class="nav-drawer" id="nav-drawer" role="dialog" aria-modal="true">
  <div class="nav-drawer-backdrop" id="nav-backdrop"></div>
  <div class="nav-drawer-panel">
    <div class="nav-drawer-logo"><?= htmlspecialchars($siteName) ?></div>
    <div class="nav-drawer-tagline">Premium Residential Building · Nairobi</div>
    <a href="index.php"         onclick="closeDrawer()"><span class="nav-icon">🏠</span> Home</a>
    <a href="index.php#about"   onclick="closeDrawer()"><span class="nav-icon">🏢</span> About</a>
    <a href="index.php#pricing" onclick="closeDrawer()"><span class="nav-icon">💳</span> Change Plan</a>
    <a href="apartments.php"    onclick="closeDrawer()"><span class="nav-icon">🔍</span> Vacancies</a>
    <a href="payroll.php"       onclick="closeDrawer()"><span class="nav-icon">💼</span> Payroll</a>
  </div>
</div>

<!-- ── Payment page ── -->
<div class="payment-page">
  <div class="payment-box">

    <a href="index.php#pricing" class="back-link">← Change plan</a>

    <!-- M-Pesa brand -->
    <div class="mpesa-brand">
      <div class="mpesa-logo-circle">📱</div>
      <div class="mpesa-brand-text">
        <div class="brand-name">M-PESA</div>
        <div class="brand-sub">Lipa Na M-Pesa &nbsp;·&nbsp; Secure Mobile Payment</div>
      </div>
    </div>

    <!-- Plan summary (from DB) -->
    <div class="plan-summary">
      <div style="flex:1;">
        <div class="plan-label">Selected Plan</div>
        <div style="font-weight:700;font-size:1rem;margin:.2rem 0;">
          <?= htmlspecialchars($plan['plan_name']) ?>
        </div>
        <div class="plan-features">
          <?php foreach (array_slice($plan['features'], 0, 3) as $feat): ?>
          <div class="plan-feature"><span class="pf-check">✓</span> <?= htmlspecialchars($feat) ?></div>
          <?php endforeach; ?>
          <?php if (count($plan['features']) > 3): ?>
          <div class="plan-feature" style="color:var(--text-faint);">
            + <?= count($plan['features']) - 3 ?> more features
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div style="text-align:right;margin-left:1rem;">
        <div class="plan-amount">KES <?= number_format((float)$plan['price_kes']) ?></div>
        <div style="font-size:.75rem;color:var(--text-faint);"><?= (int)$plan['duration_days'] ?> day<?= $plan['duration_days'] > 1 ? 's' : '' ?> access</div>
      </div>
    </div>

    <!-- ══ STEP 1: Enter details ══ -->
    <div id="step-details">
      <div class="section-label">Your Details</div>

      <div class="form-group">
        <label for="full-name">Full Name</label>
        <input type="text" id="full-name"
               value="<?= htmlspecialchars($user['name'] ?? '') ?>"
               placeholder="John Kamau"
               autocomplete="name" />
      </div>
      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email"
               value="<?= htmlspecialchars($user['email'] ?? '') ?>"
               placeholder="john@email.com"
               autocomplete="email" />
      </div>

      <hr class="divider" />

      <div class="section-label">M-Pesa Safaricom Number</div>
      <p style="font-size:.83rem;color:var(--text-muted);margin-bottom:1rem;">
        Enter the number registered to your M-Pesa account. You'll receive an STK push prompt to confirm payment.
      </p>
      <div class="form-group">
        <label for="mpesa-phone">Safaricom Number</label>
        <div class="phone-wrap">
          <div class="phone-prefix">🇰🇪 +254</div>
          <input type="tel" id="mpesa-phone"
                 placeholder="7XX XXX XXX"
                 maxlength="9"
                 oninput="this.value=this.value.replace(/\D/g,'')"
                 autocomplete="tel" />
        </div>
        <div style="font-size:.75rem;color:var(--text-faint);margin-top:.4rem;">
          Enter 9 digits without the leading 0 — e.g. 712345678
        </div>
      </div>

      <button class="btn btn-full"
              id="pay-btn"
              style="background:linear-gradient(135deg,#006633,#00a650);color:#fff;border:none;padding:.9rem;font-size:.95rem;border-radius:10px;cursor:pointer;font-weight:600;margin-top:.25rem;"
              onclick="initiateMpesa()">
        📲 &nbsp;Send M-Pesa Request &nbsp;·&nbsp; KES <?= number_format((float)$plan['price_kes']) ?>
      </button>

      <div class="secure-note">🔒 &nbsp;Powered by Safaricom Daraja API &nbsp;·&nbsp; SSL Secured</div>
    </div>

    <!-- ══ STEP 2: STK Push sent ══ -->
    <div id="stk-panel">
      <div style="text-align:center;margin-bottom:1.5rem;">
        <div style="font-size:3rem;margin-bottom:.5rem;">📲</div>
        <h3 style="font-size:1.25rem;margin-bottom:.3rem;">Check Your Phone!</h3>
        <p style="font-size:.88rem;color:var(--text-muted);">M-Pesa request sent to</p>
        <div class="phone-pill" style="margin:.5rem auto;justify-content:center;">
          🇰🇪 &nbsp;<strong id="phone-display">+254 —</strong>
        </div>
      </div>

      <div class="stk-steps">
        <div class="stk-step done" id="stk-s1">
          <span class="si">✅</span>
          <div class="st"><strong>Request Sent</strong><span>STK push dispatched to Safaricom</span></div>
          <span class="stk-tick">✓</span>
        </div>
        <div class="stk-step active" id="stk-s2">
          <span class="si">🔔</span>
          <div class="st"><strong>Waiting for PIN</strong><span>Enter your M-Pesa PIN on your phone</span></div>
          <span class="stk-spinner" id="sp2"></span>
        </div>
        <div class="stk-step" id="stk-s3">
          <span class="si">✅</span>
          <div class="st"><strong>Payment Confirmed</strong><span>Transaction verified by M-Pesa</span></div>
        </div>
        <div class="stk-step" id="stk-s4">
          <span class="si">🔓</span>
          <div class="st"><strong>Access Granted</strong><span>Redirecting to vacancies…</span></div>
        </div>
      </div>

      <div class="stk-actions">
        <button class="btn-resend" onclick="resendPush()">🔄 Resend request</button>
        <button class="btn-cancel-stk" onclick="cancelPayment()">✕ Cancel &amp; change number</button>
      </div>

      <hr class="divider" />

      <div class="section-label">Didn't receive the prompt?</div>
      <div class="ussd-box">
        Dial <span class="ussd-code">*150*00#</span> on your phone<br/>
        Select <strong>Lipa na M-Pesa</strong> → <strong>Pay Bill</strong><br/>
        Business No: <span class="ussd-code"><?= htmlspecialchars(MPESA_SHORTCODE) ?></span><br/>
        Account No: <span class="ussd-code" id="ussd-ref"><?= htmlspecialchars($txRef) ?></span><br/>
        Amount: <span class="ussd-code">KES <?= number_format((float)$plan['price_kes']) ?></span>
      </div>
      <button class="btn btn-outline btn-full" style="margin-top:1rem;" onclick="manualConfirm()">
        I've Completed the Payment
      </button>
    </div>

  </div><!-- /payment-box -->
</div><!-- /payment-page -->

<!-- Success overlay -->
<div id="success-overlay">
  <div class="success-box">
    <div class="si">🎉</div>
    <h2>Payment Confirmed!</h2>
    <p>M-Pesa payment received. Your <strong><?= htmlspecialchars($plan['plan_name']) ?></strong> is now active for <strong><?= (int)$plan['duration_days'] ?> day<?= $plan['duration_days'] > 1 ? 's' : '' ?></strong>.</p>
    <div class="progress-bar"><div class="progress-fill" id="progress-fill"></div></div>
    <div class="redirect-note">Redirecting to vacancies in 3 seconds…</div>
  </div>
</div>

<div class="toast" id="toast"></div>

<!-- Pass PHP data to JS -->
<script>
  const PLAN_KEY      = <?= json_encode($plan['plan_key']) ?>;
  const PLAN_NAME     = <?= json_encode($plan['plan_name']) ?>;
  const PLAN_PRICE_KES = <?= (int)$plan['price_kes'] ?>;
  const PLAN_DAYS     = <?= (int)$plan['duration_days'] ?>;
  const TX_REF        = <?= json_encode($txRef) ?>;
  const CURRENT_USER  = <?= json_encode($user ? ['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email']] : null) ?>;
</script>
<script src="script.js"></script>
<script>
  /* ── Nav drawer ── */
  const toggle   = document.getElementById('nav-toggle');
  const drawer   = document.getElementById('nav-drawer');
  const backdrop = document.getElementById('nav-backdrop');
  function openDrawer()  { toggle.classList.add('open'); drawer.classList.add('open'); toggle.setAttribute('aria-expanded','true'); document.body.style.overflow='hidden'; }
  function closeDrawer() { toggle.classList.remove('open'); drawer.classList.remove('open'); toggle.setAttribute('aria-expanded','false'); document.body.style.overflow=''; }
  toggle.addEventListener('click', () => drawer.classList.contains('open') ? closeDrawer() : openDrawer());
  backdrop.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', e => { if (e.key==='Escape') closeDrawer(); });

  /* ── Pre-fill name/email from CURRENT_USER ── */
  if (CURRENT_USER) {
    if (!document.getElementById('full-name').value) document.getElementById('full-name').value = CURRENT_USER.name;
    if (!document.getElementById('email').value)     document.getElementById('email').value = CURRENT_USER.email;
  }

  let storedName = '', storedEmail = '', storedPhone = '';
  let stkTimer   = null;
  let checkoutId = null;

  /* ── Validate & trigger STK Push ── */
  async function initiateMpesa() {
    const name  = document.getElementById('full-name').value.trim();
    const email = document.getElementById('email').value.trim();
    const phone = document.getElementById('mpesa-phone').value.trim();

    if (!name)                     { showToast('Please enter your full name.', 'error'); return; }
    if (!email || !email.includes('@')) { showToast('Please enter a valid email.', 'error'); return; }
    if (!phone || phone.length < 9) { showToast('Please enter a valid 9-digit Safaricom number.', 'error'); return; }

    storedName  = name;
    storedEmail = email;
    storedPhone = '+254' + phone;

    document.getElementById('phone-display').textContent = storedPhone;

    const btn = document.getElementById('pay-btn');
    btn.disabled = true;
    btn.textContent = '⏳ Sending request…';

    /* POST to PHP Daraja endpoint */
    const res = await apiPost('mpesa.php', {
      action  : 'stk_push',
      phone   : storedPhone,
      amount  : PLAN_PRICE_KES,
      ref     : TX_REF,
      desc    : 'OJ Apartment ' + PLAN_NAME + ' access',
      user_id : CURRENT_USER ? CURRENT_USER.id : 0,
    });

    btn.disabled = false;
    btn.textContent = '📲 Send M-Pesa Request · KES ' + PLAN_PRICE_KES.toLocaleString();

    if (!res.success) {
      showToast(res.message || 'STK Push failed. Please try again.', 'error');
      return;
    }

    checkoutId = res.data?.checkout_request_id || null;

    /* Switch to STK panel */
    document.getElementById('step-details').style.display = 'none';
    document.getElementById('stk-panel').style.display    = 'block';

    /* Start polling if we have a checkout ID */
    if (checkoutId) {
      pollPaymentStatus(checkoutId);
    } else {
      simulateStkFlow();  /* Sandbox: no real polling available */
    }
  }

  /* ── Poll the DB via api/mpesa.php?action=status ── */
  function pollPaymentStatus(cid, tries = 0) {
    if (tries >= 20) {
      showToast('Payment not confirmed yet. Use USSD fallback or resend.', 'error');
      return;
    }
    setTimeout(async () => {
      const res = await apiGet('mpesa.php', { action: 'status', checkout_request_id: cid });
      const status = res.data?.transaction?.status;
      if (status === 'success') {
        completePayment();
      } else if (status === 'failed') {
        showToast('Payment was declined or cancelled. Please try again.', 'error');
        cancelPayment();
      } else {
        /* Still pending — advance spinner visually */
        if (tries === 3)  stepActive('stk-s3');
        pollPaymentStatus(cid, tries + 1);
      }
    }, 3000);
  }

  /* ── Sandbox simulation (no real Daraja) ── */
  function simulateStkFlow() {
    clearTimeout(stkTimer);
    stkTimer = setTimeout(() => {
      stepDone('stk-s2', 'sp2');
      stepActive('stk-s3');
      setTimeout(() => { stepDone('stk-s3'); stepActive('stk-s4'); setTimeout(completePayment, 800); }, 2000);
    }, 5000);
  }

  function stepDone(id, spinnerId) {
    const el = document.getElementById(id);
    el.classList.remove('active');
    el.classList.add('done');
    if (spinnerId) document.getElementById(spinnerId)?.remove();
    const tick = document.createElement('span');
    tick.className = 'stk-tick'; tick.textContent = '✓';
    el.appendChild(tick);
  }
  function stepActive(id) {
    const el = document.getElementById(id);
    el.classList.add('active');
    const sp = document.createElement('span');
    sp.className = 'stk-spinner';
    el.appendChild(sp);
  }

  /* ── Payment complete ── */
  async function completePayment() {
    stepDone('stk-s3'); stepActive('stk-s4');
    stepDone('stk-s4');

    /* Cache access locally so apartments.php JS can read it immediately */
    await grantAccess(PLAN_KEY, storedName, storedEmail);

    /* Register user if not logged in */
    if (!CURRENT_USER) {
      const tempPass = 'Oj' + Math.random().toString(36).slice(-8) + '!';
      const reg = await registerUser(storedName, storedEmail, storedPhone, tempPass);
      if (reg.success) await loginUser(storedEmail, tempPass);
    }

    document.getElementById('success-overlay').classList.add('show');
    setTimeout(() => { document.getElementById('progress-fill').style.width = '100%'; }, 100);
    setTimeout(() => { window.location.href = 'apartments.php'; }, 3300);
  }

  /* ── Resend STK ── */
  async function resendPush() {
    checkoutId = null;
    resetStkSteps();
    showToast('📲 New M-Pesa request sent to ' + storedPhone);
    const res = await apiPost('mpesa.php', {
      action: 'stk_push', phone: storedPhone, amount: PLAN_PRICE_KES,
      ref: TX_REF, desc: 'OJ Apartment ' + PLAN_NAME,
      user_id: CURRENT_USER ? CURRENT_USER.id : 0,
    });
    if (res.success) {
      checkoutId = res.data?.checkout_request_id || null;
      if (checkoutId) pollPaymentStatus(checkoutId); else simulateStkFlow();
    } else {
      showToast(res.message || 'Resend failed.', 'error');
    }
  }

  function resetStkSteps() {
    clearTimeout(stkTimer);
    ['stk-s2','stk-s3','stk-s4'].forEach(id => {
      const el = document.getElementById(id);
      el.classList.remove('done','active');
      el.querySelectorAll('.stk-tick,.stk-spinner').forEach(e => e.remove());
    });
    document.getElementById('stk-s2').classList.add('active');
    const sp = document.createElement('span'); sp.className = 'stk-spinner'; sp.id = 'sp2';
    document.getElementById('stk-s2').appendChild(sp);
  }

  /* ── Cancel & go back ── */
  function cancelPayment() {
    clearTimeout(stkTimer);
    document.getElementById('stk-panel').style.display    = 'none';
    document.getElementById('step-details').style.display = 'block';
  }

  /* ── Manual/USSD confirm ── */
  function manualConfirm() {
    clearTimeout(stkTimer);
    completePayment();
  }
</script>
</body>
</html>
