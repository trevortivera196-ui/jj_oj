<?php
/* =========================================================
   OJ APARTMENT — Vacancies / Apartments Page (PHP)
   - Server-side access gate: checks session + DB purchase
   - Renders apartment grid directly from the database
   - Supports URL filter params: ?type=&status=&floor=&max_rent=
   - Reservation handled via AJAX to api/apartments.php
   ========================================================= */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

startSession();

/* ── Access check ── */
$user       = currentUser();          // null if not logged in
$hasAccess  = false;
$accessData = null;

if ($user) {
    $accessData = DB::queryOne(
        'SELECT plan, expires_at FROM access_purchases
          WHERE user_id = ? AND expires_at > NOW()
          ORDER BY expires_at DESC LIMIT 1',
        [$user['id']]
    );
    $hasAccess = (bool)$accessData;
}

/* ── Filters from URL ── */
$filterType   = sanitize($_GET['type']     ?? '');
$filterStatus = sanitize($_GET['status']   ?? '');
$filterFloor  = (int)($_GET['floor']       ?? 0);
$filterMax    = (float)($_GET['max_rent']  ?? 0);

/* ── Load apartments from DB (only if access granted) ── */
$apartments   = [];
$totalCount   = 0;
$availCount   = 0;

if ($hasAccess) {
    $sql    = 'SELECT * FROM apartments WHERE 1=1';
    $params = [];

    if ($filterType)   { $sql .= ' AND type = ?';    $params[] = $filterType; }
    if ($filterStatus) { $sql .= ' AND status = ?';  $params[] = $filterStatus; }
    if ($filterFloor)  { $sql .= ' AND floor = ?';   $params[] = $filterFloor; }
    if ($filterMax)    { $sql .= ' AND rent <= ?';   $params[] = $filterMax; }

    $sql .= ' ORDER BY floor ASC, unit ASC';

    $apartments = DB::query($sql, $params);

    foreach ($apartments as &$apt) {
        $apt['amenities'] = json_decode($apt['amenities'] ?? '[]', true);
        // Check for local reservation (user's own)
        if ($user) {
            $res = DB::queryOne(
                'SELECT id FROM reservations
                  WHERE apartment_id = ? AND status IN ("pending","confirmed")
                  LIMIT 1',
                [$apt['id']]
            );
            if ($res) $apt['status'] = 'reserved';
        }
    }
    unset($apt);

    $totalCount = count($apartments);
    $availCount = count(array_filter($apartments, fn($a) => $a['status'] === 'available'));
}

/* ── Plan label map ── */
$planLabels = ['basic' => 'Basic Access', 'standard' => 'Standard Access', 'premium' => 'Premium Access'];

/* ── Status badge helper ── */
function statusBadge(string $status): string {
    return match($status) {
        'available' => '<span class="apt-badge available">Available</span>',
        'reserved'  => '<span class="apt-badge reserved">Reserved</span>',
        default     => '<span class="apt-badge occupied">Occupied</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Vacancies — OJ Apartment</title>
  <link rel="stylesheet" href="styles.css" />
  <style>
    /* Nav */
    .nav-toggle{display:none;flex-direction:column;justify-content:center;gap:5px;background:none;border:none;cursor:pointer;padding:4px;z-index:201;}
    .nav-toggle span{display:block;width:26px;height:2.5px;background:var(--text);border-radius:2px;transition:transform .3s,opacity .3s;}
    .nav-toggle.open span:nth-child(1){transform:translateY(7.5px) rotate(45deg);}
    .nav-toggle.open span:nth-child(2){opacity:0;transform:scaleX(0);}
    .nav-toggle.open span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg);}
    .nav-drawer{position:fixed;inset:0;z-index:200;pointer-events:none;}
    .nav-drawer-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.7);opacity:0;transition:opacity .3s;backdrop-filter:blur(4px);}
    .nav-drawer-panel{position:absolute;top:0;right:0;width:min(320px,85vw);height:100%;background:var(--dark-card);border-left:1px solid var(--dark-border);transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;padding:5.5rem 2rem 2rem;}
    .nav-drawer.open{pointer-events:all;}
    .nav-drawer.open .nav-drawer-backdrop{opacity:1;}
    .nav-drawer.open .nav-drawer-panel{transform:translateX(0);}
    .nav-drawer-panel a{display:flex;align-items:center;gap:.75rem;padding:1rem 0;border-bottom:1px solid var(--dark-border);font-size:1rem;color:var(--text);transition:color .2s;}
    .nav-drawer-panel a:hover{color:var(--gold);}
    .nav-drawer-panel a .nav-icon{font-size:1.2rem;width:24px;text-align:center;}
    .nav-drawer-logo{font-size:1.1rem;font-weight:700;color:var(--gold);letter-spacing:2px;text-transform:uppercase;margin-bottom:.25rem;}
    .nav-drawer-tagline{font-size:.78rem;color:var(--text-muted);margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid var(--dark-border);}
    .nav-drawer-cta{margin-top:2rem;}
    @media(max-width:768px){.navbar .nav-links{display:none;}.nav-toggle{display:flex;}}

    /* Access gate */
    .access-gate{min-height:70vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:4rem 2rem;}
    .gate-icon{font-size:5rem;margin-bottom:1.5rem;filter:grayscale(1);opacity:.6;}
    .gate-title{font-size:2rem;font-weight:800;margin-bottom:.75rem;}
    .gate-sub{color:var(--text-muted);max-width:440px;margin:0 auto 2.5rem;font-size:1rem;line-height:1.7;}
    .gate-plans{display:flex;gap:1rem;flex-wrap:wrap;justify-content:center;margin-bottom:2rem;}
    .gate-plan{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:12px;padding:1.25rem 1.5rem;min-width:170px;text-align:center;transition:border-color .2s,transform .2s;}
    .gate-plan:hover{border-color:var(--gold);transform:translateY(-3px);}
    .gate-plan.featured{border-color:var(--gold);}
    .gate-plan .gp-name{font-size:.75rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);margin-bottom:.4rem;}
    .gate-plan .gp-price{font-size:1.6rem;font-weight:800;color:var(--gold);margin-bottom:.3rem;}
    .gate-plan .gp-note{font-size:.75rem;color:var(--text-faint);}

    /* Filters bar */
    .filters-bar{display:flex;gap:.85rem;flex-wrap:wrap;margin-bottom:2rem;padding:1.1rem 1.25rem;background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--radius);}
    .filters-bar select,.filters-bar input{background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:var(--radius-sm);padding:.55rem .9rem;color:var(--text);font-size:.86rem;outline:none;transition:border-color .2s;font-family:inherit;}
    .filters-bar select:focus,.filters-bar input:focus{border-color:var(--gold);}
    .filter-count{margin-left:auto;font-size:.82rem;color:var(--text-muted);align-self:center;}
    .filter-badge{background:var(--gold-dim);border:1px solid var(--gold-border);color:var(--gold);font-size:.75rem;font-weight:700;padding:.2rem .65rem;border-radius:50px;}

    /* Apartment grid */
    .apartments-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:1.5rem;}
    .apt-card{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:16px;overflow:hidden;transition:transform .25s,border-color .25s,box-shadow .25s;}
    .apt-card:hover{transform:translateY(-6px);border-color:var(--gold-border);box-shadow:0 14px 44px rgba(0,0,0,.45);}
    .apt-image{position:relative;height:215px;overflow:hidden;background:var(--dark-mid);}
    .apt-image img{width:100%;height:100%;object-fit:cover;transition:transform .4s;}
    .apt-card:hover .apt-image img{transform:scale(1.06);}
    .apt-badge{position:absolute;top:12px;right:12px;padding:.28rem .75rem;border-radius:50px;font-size:.72rem;font-weight:700;}
    .apt-badge.available{background:var(--success);color:#fff;}
    .apt-badge.reserved{background:var(--gold);color:var(--dark);}
    .apt-badge.occupied{background:var(--danger);color:#fff;}
    .apt-floor-badge{position:absolute;top:12px;left:12px;background:rgba(0,0,0,.72);color:rgba(255,255,255,.75);font-size:.72rem;padding:.28rem .7rem;border-radius:50px;backdrop-filter:blur(6px);}
    .apt-body{padding:1.4rem;}
    .apt-title{font-size:1.08rem;font-weight:700;margin-bottom:.25rem;}
    .apt-type{font-size:.78rem;color:var(--text-muted);margin-bottom:1rem;text-transform:uppercase;letter-spacing:.5px;}
    .apt-meta{display:flex;gap:.85rem;margin-bottom:1.2rem;flex-wrap:wrap;}
    .apt-meta-item{display:flex;align-items:center;gap:.35rem;font-size:.82rem;color:var(--text-muted);}
    .apt-price-row{display:flex;align-items:center;justify-content:space-between;padding-top:1rem;border-top:1px solid var(--dark-border);}
    .apt-price{font-size:1.35rem;font-weight:700;color:var(--gold);}
    .apt-price span{font-size:.78rem;color:var(--text-muted);font-weight:400;}
    .empty-state{grid-column:1/-1;text-align:center;padding:4rem 2rem;color:var(--text-muted);}
    .empty-state .es-icon{font-size:3.5rem;margin-bottom:1rem;}

    /* Detail modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:500;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s;backdrop-filter:blur(6px);}
    .modal-overlay.open{opacity:1;visibility:visible;}
    .modal{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:20px;padding:2.5rem;max-width:580px;width:100%;box-shadow:0 24px 80px rgba(0,0,0,.7);position:relative;max-height:92vh;overflow-y:auto;animation:modalIn .28s cubic-bezier(.34,1.56,.64,1) both;}
    @keyframes modalIn{from{transform:scale(.93) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
    .modal-close{position:absolute;top:1.2rem;right:1.2rem;background:var(--dark-mid);border:none;color:var(--text-muted);width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;transition:background .2s,color .2s;}
    .modal-close:hover{background:var(--danger);color:#fff;}
    .modal-detail-row{display:flex;gap:.85rem;margin-bottom:1.1rem;flex-wrap:wrap;}
    .modal-detail{background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:8px;padding:.75rem 1rem;flex:1;min-width:100px;text-align:center;}
    .modal-detail .d-label{font-size:.72rem;color:var(--text-muted);margin-bottom:.25rem;text-transform:uppercase;}
    .modal-detail .d-value{font-size:1rem;font-weight:600;}
    .amenities-list{display:flex;flex-wrap:wrap;gap:.5rem;margin:1.2rem 0;}
    .amenity-tag{background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:50px;padding:.28rem .8rem;font-size:.78rem;color:var(--text-muted);}

    /* Reserve modal */
    .reserve-modal-inner{text-align:center;}
    .reserve-modal-inner .rm-icon{font-size:3rem;margin-bottom:1rem;}

    /* Toast */
    .toast{position:fixed;bottom:2rem;right:2rem;background:var(--dark-card);border:1px solid var(--dark-border);border-left:4px solid var(--success);border-radius:var(--radius);padding:.9rem 1.4rem;font-size:.88rem;box-shadow:var(--shadow);z-index:600;transform:translateY(120px);opacity:0;transition:transform .35s cubic-bezier(.34,1.4,.64,1),opacity .3s;max-width:340px;}
    .toast.show{transform:translateY(0);opacity:1;}
    .toast.error{border-left-color:var(--danger);}

    @media(max-width:768px){.apartments-grid{grid-template-columns:1fr;}}
    @media(max-width:600px){.filters-bar{flex-direction:column;align-items:stretch;}.filter-count{margin-left:0;}}
  </style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="navbar">
  <div class="logo">OJ <span>Apartment</span></div>
  <div class="nav-links">
    <a href="index.php">Home</a>
    <a href="apartments.php" class="active">Vacancies</a>
    <a href="payroll.php">💼 Payroll</a>
    <?php if ($user): ?>
    <span style="font-size:.85rem;color:var(--text-muted);">Hi, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?></span>
    <a href="api/auth.php?action=logout" class="btn btn-outline" style="padding:.4rem 1rem;font-size:.82rem;" onclick="localStorage.clear();">Sign Out</a>
    <?php else: ?>
    <a href="index.php#pricing" class="btn btn-gold" style="padding:.5rem 1.25rem;font-size:.85rem;">Get Access</a>
    <?php endif; ?>
  </div>
  <button class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
</nav>

<div class="nav-drawer" id="nav-drawer" role="dialog" aria-modal="true" aria-label="Navigation menu">
  <div class="nav-drawer-backdrop" id="nav-backdrop"></div>
  <div class="nav-drawer-panel">
    <div class="nav-drawer-logo">OJ Apartment</div>
    <div class="nav-drawer-tagline">Premium Residential Building · Nairobi</div>
    <a href="index.php"      onclick="closeDrawer()"><span class="nav-icon">🏠</span> Home</a>
    <a href="apartments.php" onclick="closeDrawer()"><span class="nav-icon">🔍</span> Vacancies</a>
    <a href="index.php#how"  onclick="closeDrawer()"><span class="nav-icon">📋</span> How It Works</a>
    <a href="index.php#pricing" onclick="closeDrawer()"><span class="nav-icon">💳</span> Access Plans</a>
    <a href="payroll.php"    onclick="closeDrawer()"><span class="nav-icon">💼</span> Payroll</a>
    <div class="nav-drawer-cta">
      <?php if ($user && $hasAccess): ?>
      <a href="api/auth.php?action=logout" class="btn btn-outline btn-full" onclick="localStorage.clear();">Sign Out</a>
      <?php else: ?>
      <a href="payment.php?plan=standard&amount=15" class="btn btn-gold btn-full" onclick="closeDrawer()">Get Access</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$hasAccess): ?>
<!-- ══════════════════════════════════════
     ACCESS GATE
══════════════════════════════════════ -->
<div class="access-gate">
  <div class="gate-icon">🔒</div>
  <h2 class="gate-title">Access Required</h2>
  <p class="gate-sub">This page is available to paying members only. Choose a plan below and pay via M-Pesa to instantly unlock all <?= DB::queryOne('SELECT COUNT(*) n FROM apartments WHERE status="available"')['n'] ?? 12 ?> available units.</p>

  <div class="gate-plans">
    <?php
    $plans = DB::query('SELECT * FROM access_plans WHERE is_active=1 ORDER BY sort_order ASC');
    foreach ($plans as $plan):
      $features = json_decode($plan['features'] ?? '[]', true);
    ?>
    <div class="gate-plan <?= $plan['is_featured'] ? 'featured' : '' ?>">
      <div class="gp-name"><?= htmlspecialchars($plan['plan_name']) ?></div>
      <div class="gp-price">KES <?= number_format((float)$plan['price_kes']) ?></div>
      <div class="gp-note"><?= (int)$plan['duration_days'] ?> day<?= $plan['duration_days'] > 1 ? 's' : '' ?> access</div>
      <a href="payment.php?plan=<?= urlencode($plan['plan_key']) ?>&amount=<?= $plan['price_usd'] ?>"
         class="btn <?= $plan['is_featured'] ? 'btn-gold' : 'btn-outline' ?> btn-full"
         style="margin-top:1rem;padding:.6rem 1rem;font-size:.85rem;">
        Choose Plan
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <a href="index.php" style="font-size:.85rem;color:var(--text-muted);">← Back to homepage</a>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════
     VACANCY LISTINGS
══════════════════════════════════════ -->
<div class="apartments-page">

  <!-- Page header -->
  <div class="page-header">
    <h1>Available Units</h1>
    <p>Browse all vacancies at OJ Apartment —
      <strong style="color:var(--gold);"><?= $availCount ?></strong> unit<?= $availCount !== 1 ? 's' : '' ?> currently available.
    </p>
  </div>

  <!-- Access banner -->
  <div class="access-banner">
    ✅ &nbsp;<strong><?= htmlspecialchars($planLabels[$accessData['plan']] ?? 'Active Access') ?></strong>
    &nbsp;— Full access granted. Expires:
    <strong style="margin-left:.3rem;">
      <?= date('D, d M Y', strtotime($accessData['expires_at'])) ?>
    </strong>
  </div>

  <!-- Filters (GET form — no JS required) -->
  <form method="GET" action="apartments.php" class="filters-bar">
    <select name="type" onchange="this.form.submit()">
      <option value="">All Types</option>
      <?php foreach (['Studio','1-Bedroom','2-Bedroom','3-Bedroom','Penthouse'] as $t): ?>
      <option value="<?= $t ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>

    <select name="status" onchange="this.form.submit()">
      <option value="">All Status</option>
      <option value="available" <?= $filterStatus === 'available' ? 'selected' : '' ?>>Available</option>
      <option value="reserved"  <?= $filterStatus === 'reserved'  ? 'selected' : '' ?>>Reserved</option>
    </select>

    <select name="floor" onchange="this.form.submit()">
      <option value="">All Floors</option>
      <?php for ($f = 1; $f <= 8; $f++): ?>
      <option value="<?= $f ?>" <?= $filterFloor === $f ? 'selected' : '' ?>>Floor <?= $f ?></option>
      <?php endfor; ?>
    </select>

    <input type="number" name="max_rent"
           placeholder="Max rent (KES)"
           value="<?= $filterMax ? (int)$filterMax : '' ?>"
           style="width:170px;" />

    <button type="submit" class="btn btn-gold" style="padding:.55rem 1.25rem;font-size:.85rem;">Filter</button>
    <?php if ($filterType || $filterStatus || $filterFloor || $filterMax): ?>
    <a href="apartments.php" class="btn btn-outline" style="padding:.55rem 1rem;font-size:.82rem;">✕ Clear</a>
    <?php endif; ?>

    <span class="filter-count">
      <?= $totalCount ?> unit<?= $totalCount !== 1 ? 's' : '' ?> shown
      <?php if ($availCount > 0): ?>
      &nbsp;<span class="filter-badge"><?= $availCount ?> available</span>
      <?php endif; ?>
    </span>
  </form>

  <!-- Grid -->
  <div class="apartments-grid">
    <?php if (empty($apartments)): ?>
    <div class="empty-state">
      <div class="es-icon">🔍</div>
      <h3 style="margin-bottom:.5rem;">No units match your filters</h3>
      <p>Try adjusting or <a href="apartments.php">clearing</a> the filters.</p>
    </div>
    <?php else: ?>
      <?php foreach ($apartments as $apt):
        $amenities = $apt['amenities'] ?? [];
      ?>
      <div class="apt-card" id="apt-<?= $apt['id'] ?>">
        <div class="apt-image">
          <?php if ($apt['image_url']): ?>
          <img src="<?= htmlspecialchars($apt['image_url']) ?>" alt="Unit <?= htmlspecialchars($apt['unit']) ?>" loading="lazy" />
          <?php else: ?>
          <div style="height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-faint);font-size:3rem;">🏠</div>
          <?php endif; ?>
          <?= statusBadge($apt['status']) ?>
          <span class="apt-floor-badge">Floor <?= (int)$apt['floor'] ?></span>
        </div>
        <div class="apt-body">
          <div class="apt-title">Unit <?= htmlspecialchars($apt['unit']) ?></div>
          <div class="apt-type"><?= htmlspecialchars($apt['type']) ?></div>
          <div class="apt-meta">
            <?php if ($apt['beds'] > 0): ?>
            <div class="apt-meta-item">🛏️ <?= (int)$apt['beds'] ?> Bed<?= $apt['beds'] > 1 ? 's' : '' ?></div>
            <?php endif; ?>
            <div class="apt-meta-item">🚿 <?= (int)$apt['baths'] ?> Bath<?= $apt['baths'] > 1 ? 's' : '' ?></div>
            <div class="apt-meta-item">📐 <?= (float)$apt['size_sqm'] ?> m²</div>
            <div class="apt-meta-item">🏢 Floor <?= (int)$apt['floor'] ?></div>
          </div>
          <div class="apt-price-row">
            <div class="apt-price">
              KES <?= number_format((float)$apt['rent']) ?><span>/mo</span>
            </div>
            <button class="btn btn-gold"
                    style="padding:.55rem 1.25rem;font-size:.85rem;"
                    onclick="openAptModal(<?= htmlspecialchars(json_encode([
                      'id'          => (int)$apt['id'],
                      'unit'        => $apt['unit'],
                      'floor'       => (int)$apt['floor'],
                      'type'        => $apt['type'],
                      'status'      => $apt['status'],
                      'rent'        => (float)$apt['rent'],
                      'size_sqm'    => (float)$apt['size_sqm'],
                      'beds'        => (int)$apt['beds'],
                      'baths'       => (int)$apt['baths'],
                      'description' => $apt['description'] ?? '',
                      'amenities'   => $amenities,
                      'image_url'   => $apt['image_url'] ?? '',
                    ])) ?>)">
              View Details
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div><!-- /apartments-page -->
<?php endif; ?>

<!-- ── Detail Modal ── -->
<div class="modal-overlay" id="apt-modal" role="dialog" aria-modal="true" aria-label="Apartment details">
  <div class="modal">
    <button class="modal-close" onclick="closeAptModal()" aria-label="Close">✕</button>
    <div id="modal-body"></div>
  </div>
</div>

<!-- ── Reserve Modal ── -->
<div class="modal-overlay" id="reserve-modal" role="dialog" aria-modal="true" aria-label="Reserve unit">
  <div class="modal" style="max-width:440px;">
    <button class="modal-close" onclick="closeReserveModal()" aria-label="Close">✕</button>
    <div class="reserve-modal-inner">
      <div class="rm-icon">🏠</div>
      <h2 style="margin-bottom:.4rem;">Reserve This Unit?</h2>
      <p id="rm-desc" style="color:var(--text-muted);font-size:.9rem;margin-bottom:2rem;"></p>
      <div class="form-group" style="text-align:left;">
        <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:.4rem;text-transform:uppercase;">Preferred Move-in Date</label>
        <input type="date" id="movein-date" style="width:100%;background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:8px;padding:.7rem 1rem;color:var(--text);font-size:.9rem;outline:none;" />
      </div>
      <div class="form-group" style="text-align:left;margin-top:1rem;">
        <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:.4rem;text-transform:uppercase;">Notes (optional)</label>
        <input type="text" id="reserve-notes" placeholder="Any special requests…" style="width:100%;background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:8px;padding:.7rem 1rem;color:var(--text);font-size:.9rem;outline:none;" />
      </div>
      <div style="display:flex;gap:1rem;margin-top:1.5rem;">
        <button class="btn btn-outline btn-full" onclick="closeReserveModal()">Cancel</button>
        <button class="btn btn-gold btn-full" onclick="confirmReservation()">Confirm Reservation</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

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
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeDrawer(); closeAptModal(); closeReserveModal(); } });

  /* Close modals on overlay click */
  document.getElementById('apt-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeAptModal(); });
  document.getElementById('reserve-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeReserveModal(); });

  /* Set min date for move-in */
  document.getElementById('movein-date').min = new Date().toISOString().split('T')[0];
  document.getElementById('movein-date').value = new Date().toISOString().split('T')[0];

  /* ── Apartment detail modal ── */
  let currentApt = null;

  function openAptModal(apt) {
    currentApt = apt;
    const canReserve = apt.status === 'available';
    const amenitiesHtml = apt.amenities.map(a => `<span class="amenity-tag">✓ ${a}</span>`).join('');

    document.getElementById('modal-body').innerHTML = `
      ${apt.image_url ? `<img src="${apt.image_url}" alt="Unit ${apt.unit}" style="width:100%;height:220px;object-fit:cover;border-radius:12px;margin-bottom:1.5rem;"/>` : ''}
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem;">
        <h2>Unit ${apt.unit} — ${apt.type}</h2>
        <span class="apt-badge ${apt.status}" style="position:static;">${apt.status.charAt(0).toUpperCase()+apt.status.slice(1)}</span>
      </div>
      <p style="color:var(--text-muted);font-size:.88rem;margin-bottom:1.5rem;">Floor ${apt.floor} &nbsp;·&nbsp; ${apt.size_sqm} m² &nbsp;·&nbsp; OJ Apartment</p>
      <div class="modal-detail-row">
        ${apt.beds > 0 ? `<div class="modal-detail"><div class="d-label">Bedrooms</div><div class="d-value">🛏️ ${apt.beds}</div></div>` : ''}
        <div class="modal-detail"><div class="d-label">Bathrooms</div><div class="d-value">🚿 ${apt.baths}</div></div>
        <div class="modal-detail"><div class="d-label">Size</div><div class="d-value">📐 ${apt.size_sqm} m²</div></div>
        <div class="modal-detail"><div class="d-label">Floor</div><div class="d-value">🏢 ${apt.floor}</div></div>
      </div>
      ${apt.description ? `<p style="color:var(--text-muted);font-size:.9rem;margin:.75rem 0;">${apt.description}</p>` : ''}
      ${amenitiesHtml ? `<div style="font-size:.72rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:.6rem;">Amenities</div><div class="amenities-list">${amenitiesHtml}</div>` : ''}
      <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--dark-border);padding-top:1.25rem;margin-top:.5rem;">
        <div>
          <div style="font-size:2rem;font-weight:800;color:var(--gold);">KES ${Number(apt.rent).toLocaleString()}<span style="font-size:.85rem;font-weight:400;color:var(--text-muted);">/mo</span></div>
          <div style="font-size:.78rem;color:var(--text-muted);">Excl. service charge</div>
        </div>
        ${canReserve
          ? `<button class="btn btn-gold" onclick="openReserveModal(currentApt)">Reserve This Unit</button>`
          : `<span style="color:var(--text-muted);font-size:.88rem;">Not available for reservation</span>`}
      </div>`;

    document.getElementById('apt-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeAptModal() {
    document.getElementById('apt-modal').classList.remove('open');
    document.body.style.overflow = '';
  }

  /* ── Reserve modal ── */
  function openReserveModal(apt) {
    currentApt = apt;
    document.getElementById('rm-desc').textContent =
      `Unit ${apt.unit} (${apt.type}, Floor ${apt.floor}) at KES ${Number(apt.rent).toLocaleString()}/mo.`;
    closeAptModal();
    document.getElementById('reserve-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeReserveModal() {
    document.getElementById('reserve-modal').classList.remove('open');
    document.body.style.overflow = '';
  }

  async function confirmReservation() {
    if (!currentApt) return;
    const date  = document.getElementById('movein-date').value;
    const notes = document.getElementById('reserve-notes').value;

    const btn = document.querySelector('#reserve-modal .btn-gold');
    btn.disabled = true; btn.textContent = 'Reserving…';

    const res = await apiPost('apartments.php',
      { action: 'reserve', apartment_id: currentApt.id, move_in_date: date, notes },
      {}
    );

    btn.disabled = false; btn.textContent = 'Confirm Reservation';

    if (res.success) {
      closeReserveModal();
      showToast(`✅ Unit ${currentApt.unit} reserved! Check your email for confirmation.`);
      /* Update the card badge without full page reload */
      const card = document.getElementById('apt-' + currentApt.id);
      if (card) {
        card.querySelector('.apt-badge').className = 'apt-badge reserved';
        card.querySelector('.apt-badge').textContent = 'Reserved';
        card.querySelector('.btn-gold').disabled = true;
        card.querySelector('.btn-gold').textContent = 'Reserved';
      }
    } else {
      showToast(res.message || 'Reservation failed. Please try again.', 'error');
    }
  }
</script>
</body>
</html>
