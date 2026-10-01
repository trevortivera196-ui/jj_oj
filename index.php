<?php
/* =========================================================
   OJ APARTMENT — Homepage (Server-Side Rendered)
   Pulls all content from the database via home.php helpers.
   Falls back gracefully if DB is unavailable.
   ========================================================= */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/home.php';   // data fetchers

/* ── Load all homepage data in one pass ── */
$dbOk = true;
try {
    $stats        = getStats();
    $gallery      = getGallery();
    $amenities    = getAmenities();
    $testimonials = getTestimonials();
    $plans        = getPlans();
    $settings     = getSettings();
    $faqs         = getFaqs();
    $news         = getNews(3);
    $social       = getSocial();
} catch (Throwable $e) {
    $dbOk = false;
    // Fallback values so the page still renders
    $stats        = ['total_units' => 48, 'available_units' => 12, 'total_floors' => 8, 'avg_rating' => 5];
    $gallery      = [];
    $amenities    = [];
    $testimonials = [];
    $plans        = [];
    $settings     = [];
    $faqs         = [];
    $news         = [];
    $social       = [];
}

/* ── Helper: get a setting value with fallback ── */
function cfg(string $key, string $fallback = ''): string {
    global $settings;
    return htmlspecialchars($settings[$key] ?? $fallback, ENT_QUOTES, 'UTF-8');
}

/* ── Handle contact form POST ── */
$formSuccess = false;
$formError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cf_submit'])) {
    $name     = trim($_POST['cf_name']    ?? '');
    $contact  = trim($_POST['cf_contact'] ?? '');
    $interest = trim($_POST['cf_interest']?? 'General information');
    $message  = trim($_POST['cf_message'] ?? '');

    if (!$name)    $formError = 'Please enter your name.';
    elseif (!$contact) $formError = 'Please enter your email or phone.';
    elseif (!$message) $formError = 'Please write a message.';
    else {
        try {
            $email = filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : null;
            $phone = !$email ? $contact : null;

            DB::insert(
                'INSERT INTO enquiries (name, email, phone, interest, message, status, created_at)
                 VALUES (?, ?, ?, ?, ?, "new", NOW())',
                [$name, $email, $phone, $interest, $message]
            );

            /* Email admin */
            sendMail(MAIL_TO_ADMIN, "New Enquiry from {$name}", "
                <h2>New Enquiry — OJ Apartment</h2>
                <table cellpadding='6'>
                  <tr><td><strong>Name:</strong></td><td>{$name}</td></tr>
                  <tr><td><strong>Contact:</strong></td><td>{$contact}</td></tr>
                  <tr><td><strong>Interest:</strong></td><td>{$interest}</td></tr>
                  <tr><td><strong>Message:</strong></td><td>{$message}</td></tr>
                </table>
            ");

            /* Auto-reply if email provided */
            if ($email) {
                sendMail($email, 'We received your message — OJ Apartment', "
                    <h2>Thank you, {$name}!</h2>
                    <p>We've received your enquiry about <strong>{$interest}</strong>
                       and will respond within 24 hours.</p>
                    <p>📞 " . cfg('contact_phone', '+254 700 000 000') . "
                    &nbsp;|&nbsp; 📧 " . cfg('contact_email', 'info@ojapartment.co.ke') . "</p>
                ");
            }

            $formSuccess = true;
        } catch (Throwable $e) {
            $formError = 'Could not send message. Please try again.';
        }
    }
}

/* ── SEO values ── */
$metaTitle = cfg('site_name', 'OJ Apartment') . ' — ' . cfg('site_tagline', 'Luxury Living in Nairobi');
$metaDesc  = cfg('meta_description', 'OJ Apartment — Premium residential units in Nairobi. Browse vacancies, pay via M-Pesa, and reserve your unit online.');
$metaKeys  = cfg('meta_keywords', 'apartments nairobi, luxury apartments, OJ apartment');
$siteName  = cfg('site_name', 'OJ Apartment');
$heroHead  = cfg('hero_heading', 'Find Your Perfect Home at OJ');
$heroSub   = cfg('hero_subtext', 'Experience luxury living with modern amenities, breathtaking views, and a vibrant community — all in one address.');
$address   = cfg('contact_address', '123 OJ Tower, Westlands, Nairobi');
$phone     = cfg('contact_phone', '+254 700 000 000');
$email     = cfg('contact_email', 'info@ojapartment.co.ke');
$hours     = cfg('office_hours', 'Mon – Sat: 8:00am – 6:00pm');
$whatsapp  = cfg('whatsapp_number', '254700000000');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= $metaTitle ?></title>
  <meta name="description" content="<?= $metaDesc ?>" />
  <meta name="keywords"    content="<?= $metaKeys ?>" />
  <!-- Open Graph -->
  <meta property="og:title"       content="<?= $metaTitle ?>" />
  <meta property="og:description" content="<?= $metaDesc ?>" />
  <meta property="og:type"        content="website" />
  <meta property="og:url"         content="<?= cfg('site_url', APP_URL) ?>" />
  <link rel="stylesheet" href="styles.css" />
  <style>
    html { scroll-behavior: smooth; }

    /* Marquee */
    .marquee-strip { background: var(--gold); color: var(--dark); font-size:.78rem; font-weight:700; letter-spacing:2px; text-transform:uppercase; padding:.45rem 0; overflow:hidden; white-space:nowrap; }
    .marquee-inner { display:inline-block; animation:marquee 30s linear infinite; }
    .marquee-inner span { margin:0 2.5rem; }
    @keyframes marquee { from{transform:translateX(100vw)} to{transform:translateX(-100%)} }

    /* Nav */
    .navbar.scrolled { box-shadow:0 4px 24px rgba(0,0,0,.5); }
    .nav-toggle { display:none; flex-direction:column; justify-content:center; gap:5px; background:none; border:none; cursor:pointer; padding:4px; z-index:201; }
    .nav-toggle span { display:block; width:26px; height:2.5px; background:var(--text); border-radius:2px; transition:transform .3s,opacity .3s; }
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
    .nav-drawer-cta{margin-top:2rem;}
    @media(max-width:768px){.navbar .nav-links{display:none;}.nav-toggle{display:flex;}}

    /* Scroll reveal */
    .reveal{opacity:0;transform:translateY(36px);transition:opacity .65s ease,transform .65s ease;}
    .reveal.visible{opacity:1;transform:translateY(0);}
    .reveal-left{opacity:0;transform:translateX(-40px);transition:opacity .65s ease,transform .65s ease;}
    .reveal-right{opacity:0;transform:translateX(40px);transition:opacity .65s ease,transform .65s ease;}
    .reveal-left.visible,.reveal-right.visible{opacity:1;transform:translateX(0);}
    .reveal-delay-1{transition-delay:.1s;} .reveal-delay-2{transition-delay:.2s;}
    .reveal-delay-3{transition-delay:.3s;} .reveal-delay-4{transition-delay:.4s;}

    /* How it works */
    .how-section{padding:5rem 2rem;background:var(--dark);text-align:center;}
    .steps{display:flex;gap:2rem;justify-content:center;flex-wrap:wrap;max-width:900px;margin:0 auto;}
    .step{flex:1;min-width:200px;max-width:240px;}
    .step-num{width:52px;height:52px;border-radius:50%;border:2px solid var(--gold);display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:700;color:var(--gold);margin:0 auto 1rem;}
    .step h3{font-size:1rem;margin-bottom:.4rem;}
    .step p{font-size:.88rem;color:var(--text-muted);}

    /* Gallery */
    .gallery-section{padding:4rem 2rem;background:var(--dark-mid);overflow:hidden;}
    .gallery-strip{display:flex;gap:1rem;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:.5rem;scrollbar-width:thin;scrollbar-color:var(--dark-border) transparent;}
    .gallery-strip::-webkit-scrollbar{height:4px;}
    .gallery-strip::-webkit-scrollbar-thumb{background:var(--dark-border);border-radius:2px;}
    .gallery-item{flex-shrink:0;width:320px;height:220px;border-radius:14px;overflow:hidden;scroll-snap-align:start;border:1px solid var(--dark-border);transition:border-color .25s;}
    .gallery-item:hover{border-color:var(--gold-border);}
    .gallery-item img{width:100%;height:100%;object-fit:cover;transition:transform .4s;}
    .gallery-item:hover img{transform:scale(1.06);}

    /* Testimonials */
    .testimonials-section{padding:5rem 2rem;background:var(--dark);text-align:center;}
    .testimonials-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.5rem;max-width:1000px;margin:0 auto;}
    .testimonial-card{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:16px;padding:2rem 1.5rem;text-align:left;transition:border-color .25s,transform .25s;position:relative;}
    .testimonial-card::before{content:'"';position:absolute;top:1.25rem;right:1.75rem;font-size:4rem;color:var(--gold-dim);font-family:Georgia,serif;line-height:1;}
    .testimonial-card:hover{border-color:var(--gold);transform:translateY(-4px);}
    .testimonial-stars{color:var(--gold);font-size:1rem;margin-bottom:.75rem;letter-spacing:2px;}
    .testimonial-text{font-size:.9rem;color:var(--text-muted);line-height:1.7;margin-bottom:1.25rem;font-style:italic;}
    .testimonial-author{display:flex;align-items:center;gap:.75rem;padding-top:1rem;border-top:1px solid var(--dark-border);}
    .testimonial-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--gold-light));display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;color:var(--dark);flex-shrink:0;}
    .testimonial-name{font-size:.9rem;font-weight:600;}
    .testimonial-unit{font-size:.75rem;color:var(--text-muted);}

    /* Map */
    .map-section{height:320px;background:var(--dark-card);position:relative;overflow:hidden;}
    .map-section iframe{width:100%;height:100%;border:0;filter:grayscale(1) brightness(.45);}
    .map-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.3);}
    .map-pin{background:var(--dark-card);border:1px solid var(--gold);border-radius:12px;padding:1rem 1.5rem;text-align:center;}
    .map-pin .pin-icon{font-size:2rem;margin-bottom:.4rem;}
    .map-pin .pin-label{font-size:.85rem;font-weight:600;}
    .map-pin .pin-addr{font-size:.75rem;color:var(--text-muted);margin-top:.2rem;}

    /* Contact */
    .contact-section{padding:5rem 2rem;background:var(--dark-mid);}
    .contact-wrap{display:grid;grid-template-columns:1fr 1fr;gap:3rem;max-width:960px;margin:0 auto;align-items:start;}
    .contact-info h2{font-size:1.8rem;font-weight:700;margin-bottom:1rem;}
    .contact-info p{color:var(--text-muted);margin-bottom:1.5rem;line-height:1.7;}
    .contact-detail{display:flex;align-items:flex-start;gap:.9rem;margin-bottom:1rem;padding:.85rem 1rem;background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--radius);transition:border-color .2s;}
    .contact-detail:hover{border-color:var(--gold-border);}
    .contact-detail .cd-icon{font-size:1.4rem;flex-shrink:0;}
    .contact-detail .cd-label{font-size:.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:.15rem;}
    .contact-detail .cd-value{font-size:.92rem;font-weight:600;}
    .contact-form-box{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:16px;padding:2.25rem 2rem;}
    .contact-form-box h3{font-size:1.1rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid var(--dark-border);}
    .cf-group{margin-bottom:1.1rem;}
    .cf-group label{display:block;font-size:.78rem;font-weight:600;color:var(--text-muted);margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.3px;}
    .cf-group input,.cf-group textarea,.cf-group select{width:100%;background:var(--dark-mid);border:1px solid var(--dark-border);border-radius:8px;padding:.72rem 1rem;color:var(--text);font-size:.9rem;outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit;}
    .cf-group input:focus,.cf-group textarea:focus,.cf-group select:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim);}
    .cf-group input::placeholder,.cf-group textarea::placeholder{color:var(--text-faint);}
    .cf-group textarea{resize:vertical;min-height:110px;}
    .form-alert{padding:.8rem 1.1rem;border-radius:8px;font-size:.88rem;margin-bottom:1rem;}
    .form-alert.success{background:var(--success-dim);border:1px solid rgba(76,175,125,.35);color:var(--success);}
    .form-alert.error{background:var(--danger-dim);border:1px solid rgba(224,92,92,.35);color:var(--danger);}

    /* FAQs */
    .faq-section{padding:5rem 2rem;background:var(--dark);}
    .faq-grid{max-width:860px;margin:0 auto;}
    .faq-item{border:1px solid var(--dark-border);border-radius:var(--radius);margin-bottom:.75rem;overflow:hidden;transition:border-color .2s;}
    .faq-item:hover{border-color:var(--gold-border);}
    .faq-question{width:100%;background:var(--dark-card);border:none;color:var(--text);text-align:left;padding:1.1rem 1.4rem;font-size:.92rem;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:1rem;}
    .faq-question .faq-chevron{color:var(--gold);font-size:1rem;transition:transform .25s;flex-shrink:0;}
    .faq-item.open .faq-chevron{transform:rotate(180deg);}
    .faq-answer{max-height:0;overflow:hidden;transition:max-height .35s ease,padding .25s;}
    .faq-item.open .faq-answer{max-height:400px;}
    .faq-answer p{padding:1rem 1.4rem 1.25rem;font-size:.88rem;color:var(--text-muted);line-height:1.75;border-top:1px solid var(--dark-border);}

    /* News */
    .news-section{padding:5rem 2rem;background:var(--dark-mid);}
    .news-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.5rem;max-width:1060px;margin:0 auto;}
    .news-card{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:16px;overflow:hidden;transition:transform .25s,border-color .25s;}
    .news-card:hover{transform:translateY(-5px);border-color:var(--gold-border);}
    .news-card-img{height:170px;overflow:hidden;background:var(--dark-mid);}
    .news-card-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s;}
    .news-card:hover .news-card-img img{transform:scale(1.06);}
    .news-card-body{padding:1.35rem 1.5rem;}
    .news-cat{font-size:.7rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gold);margin-bottom:.5rem;}
    .news-title{font-size:1rem;font-weight:700;margin-bottom:.5rem;line-height:1.35;}
    .news-excerpt{font-size:.85rem;color:var(--text-muted);line-height:1.65;margin-bottom:1rem;}
    .news-meta{font-size:.75rem;color:var(--text-faint);}

    /* Social */
    .social-strip{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;padding:2rem;}
    .social-btn{display:inline-flex;align-items:center;gap:.5rem;background:var(--dark-card);border:1px solid var(--dark-border);border-radius:50px;padding:.45rem 1rem;font-size:.82rem;color:var(--text-muted);transition:border-color .2s,color .2s,transform .2s;}
    .social-btn:hover{border-color:var(--gold);color:var(--gold);transform:translateY(-2px);}

    /* Section divider */
    .section-divider{height:1px;background:linear-gradient(90deg,transparent,var(--dark-border),transparent);}

    /* Back to top */
    #back-to-top{position:fixed;bottom:2rem;right:2rem;z-index:99;width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:var(--dark);border:none;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 18px rgba(201,168,76,.45);opacity:0;transform:translateY(16px);transition:opacity .3s,transform .3s;pointer-events:none;}
    #back-to-top.visible{opacity:1;transform:translateY(0);pointer-events:all;}

    .toast{position:fixed;bottom:5.5rem;right:2rem;background:var(--dark-card);border:1px solid var(--dark-border);border-left:4px solid var(--success);border-radius:var(--radius);padding:.9rem 1.4rem;font-size:.88rem;box-shadow:var(--shadow);z-index:600;transform:translateY(120px);opacity:0;transition:transform .35s cubic-bezier(.34,1.4,.64,1),opacity .3s;max-width:340px;}
    .toast.show{transform:translateY(0);opacity:1;}
    .toast.error{border-left-color:var(--danger);}

    @media(max-width:768px){
      .contact-wrap{grid-template-columns:1fr;}
      .gallery-item{width:260px;height:180px;}
    }
    @media(max-width:600px){
      .steps{flex-direction:column;align-items:center;}
    }
  </style>
</head>
<body>

<!-- ── Marquee ── -->
<div class="marquee-strip">
  <div class="marquee-inner">
    <span>🏙️ <?= $siteName ?></span>
    <span>✦ Premium Vacancies Available</span>
    <span>✦ Secure M-Pesa Payments</span>
    <span>✦ Modern Amenities</span>
    <span>✦ 24/7 Security</span>
    <span>✦ Book Your Unit Today</span>
    <span>🏙️ <?= $siteName ?></span>
    <span>✦ Premium Vacancies Available</span>
    <span>✦ Secure M-Pesa Payments</span>
    <span>✦ Modern Amenities</span>
  </div>
</div>

<!-- ── Navbar ── -->
<nav class="navbar" id="navbar">
  <div class="logo"><?= explode(' ', $siteName)[0] ?> <span><?= implode(' ', array_slice(explode(' ', $siteName), 1)) ?></span></div>
  <div class="nav-links">
    <a href="#hero"    data-section="hero"    class="nav-active">Home</a>
    <a href="#about"   data-section="about">About</a>
    <a href="#gallery" data-section="gallery">Gallery</a>
    <a href="#how"     data-section="how">How It Works</a>
    <a href="#pricing" data-section="pricing">Plans</a>
    <?php if (!empty($faqs)): ?>
    <a href="#faq"     data-section="faq">FAQ</a>
    <?php endif; ?>
    <a href="#contact" data-section="contact">Contact</a>
    <a href="payroll.php">💼 Payroll</a>
    <a href="payment.php?plan=standard&amount=15" class="btn btn-gold" style="padding:.5rem 1.25rem;font-size:.85rem;">View Vacancies</a>
  </div>
  <button class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
</nav>

<!-- Mobile drawer -->
<div class="nav-drawer" id="nav-drawer" role="dialog" aria-modal="true" aria-label="Navigation menu">
  <div class="nav-drawer-backdrop" id="nav-backdrop"></div>
  <div class="nav-drawer-panel">
    <div class="nav-drawer-logo"><?= $siteName ?></div>
    <div class="nav-drawer-tagline">Premium Residential Building · Nairobi</div>
    <a href="#hero"         onclick="closeDrawer()"><span class="nav-icon">🏠</span> Home</a>
    <a href="#about"        onclick="closeDrawer()"><span class="nav-icon">🏢</span> About</a>
    <a href="#gallery"      onclick="closeDrawer()"><span class="nav-icon">🖼️</span> Gallery</a>
    <a href="#how"          onclick="closeDrawer()"><span class="nav-icon">📋</span> How It Works</a>
    <a href="#pricing"      onclick="closeDrawer()"><span class="nav-icon">💳</span> Access Plans</a>
    <?php if (!empty($faqs)): ?>
    <a href="#faq"          onclick="closeDrawer()"><span class="nav-icon">❓</span> FAQ</a>
    <?php endif; ?>
    <a href="#contact"      onclick="closeDrawer()"><span class="nav-icon">📞</span> Contact</a>
    <a href="apartments.php" onclick="closeDrawer()"><span class="nav-icon">🔍</span> Vacancies</a>
    <a href="payroll.php"   onclick="closeDrawer()"><span class="nav-icon">💼</span> Payroll</a>
    <div class="nav-drawer-cta">
      <a href="payment.php?plan=standard&amount=15" class="btn btn-gold btn-full" onclick="closeDrawer()">View Vacancies</a>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     SECTION 1 — HERO
══════════════════════════════════════ -->
<section id="hero" class="hero">
  <div class="hero-bg"></div>
  <div class="hero-content">
    <div class="hero-badge reveal">✦ Premium Apartment Building — Nairobi</div>
    <h1 class="reveal reveal-delay-1">
      <?php
        // Split heading at last word for gold highlight
        $words = explode(' ', $heroHead);
        $last  = array_pop($words);
        echo implode(' ', $words) . ' <span class="highlight">' . htmlspecialchars($last) . '</span>';
      ?>
    </h1>
    <p class="reveal reveal-delay-2"><?= htmlspecialchars($heroSub) ?></p>
    <div class="hero-actions reveal reveal-delay-3">
      <a href="#pricing" class="btn btn-gold btn-lg">Get Access — View Vacancies</a>
      <a href="#about"   class="btn btn-outline btn-lg">Learn More</a>
    </div>
    <div class="hero-stats reveal reveal-delay-4">
      <div class="stat">
        <div class="stat-value" id="cnt-units"><?= (int)$stats['total_units'] ?></div>
        <div class="stat-label">Total Units</div>
      </div>
      <div class="stat">
        <div class="stat-value" id="cnt-avail"><?= (int)$stats['available_units'] ?></div>
        <div class="stat-label">Available Now</div>
      </div>
      <div class="stat">
        <div class="stat-value" id="cnt-floors"><?= (int)$stats['total_floors'] ?></div>
        <div class="stat-label">Floors</div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= number_format((float)$stats['avg_rating'], 0) ?>★</div>
        <div class="stat-label">Rated</div>
      </div>
    </div>
  </div>
</section>

<div class="section-divider"></div>

<!-- ══════════════════════════════════════
     SECTION 2 — ABOUT / AMENITIES
══════════════════════════════════════ -->
<section id="about" class="features-section">
  <div style="text-align:center;margin-bottom:3rem;">
    <div class="section-tag reveal">Why <?= $siteName ?></div>
    <h2 class="section-title reveal reveal-delay-1"><?= cfg('about_heading', 'Everything You Need, All in One Place') ?></h2>
    <p class="section-sub reveal reveal-delay-2"><?= cfg('about_subtext', 'A premium residential building designed for comfort, style, and convenience.') ?></p>
  </div>
  <div class="features-grid">
    <?php if (!empty($amenities)): ?>
      <?php $delays = [1,2,3,4,1,2,3,4]; foreach ($amenities as $i => $a): $d = $delays[$i % 8]; ?>
      <div class="feature-card reveal reveal-delay-<?= $d ?>">
        <div class="feature-icon"><?= htmlspecialchars($a['icon_emoji']) ?></div>
        <h3><?= htmlspecialchars($a['title']) ?></h3>
        <p><?= htmlspecialchars($a['description'] ?? '') ?></p>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <!-- Fallback static cards if DB unavailable -->
      <?php $fallbackAmenities = [
        ['🏋️','Fitness Center','State-of-the-art gym available to all residents 24 hours a day.'],
        ['🔒','24/7 Security','Round-the-clock security personnel and CCTV surveillance throughout.'],
        ['🏊','Swimming Pool','Rooftop infinity pool with panoramic city views for residents.'],
        ['🅿️','Secure Parking','Dedicated underground parking with remote access control.'],
        ['⚡','Backup Power','Full building generator backup ensures no power interruptions.'],
        ['📶','High-Speed WiFi','Fiber internet in every unit and all common areas included.'],
        ['🛗','Modern Elevators','Multiple high-speed elevators serving all 8 floors.'],
        ['🌿','Rooftop Garden','Lush communal garden space perfect for relaxation and events.'],
      ]; foreach ($fallbackAmenities as $i => $a): $d = ($i % 4) + 1; ?>
      <div class="feature-card reveal reveal-delay-<?= $d ?>">
        <div class="feature-icon"><?= $a[0] ?></div>
        <h3><?= $a[1] ?></h3>
        <p><?= $a[2] ?></p>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<div class="section-divider"></div>

<!-- ══════════════════════════════════════
     SECTION 3 — GALLERY
══════════════════════════════════════ -->
<section id="gallery" class="gallery-section">
  <div style="text-align:center;margin-bottom:2rem;">
    <div class="section-tag reveal">Take a Look Inside</div>
    <h2 class="section-title reveal reveal-delay-1">Explore <?= $siteName ?></h2>
  </div>
  <div class="gallery-strip reveal">
    <?php if (!empty($gallery)): ?>
      <?php foreach ($gallery as $img): ?>
      <div class="gallery-item">
        <img src="<?= htmlspecialchars($img['image_url']) ?>"
             alt="<?= htmlspecialchars($img['caption'] ?? 'Gallery image') ?>"
             loading="lazy" />
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <?php $fallbackGallery = [
        ['https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=640&q=80','Building Exterior'],
        ['https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=640&q=80','Modern Living Room'],
        ['https://images.unsplash.com/photo-1484154218962-a197022b5858?w=640&q=80','Chef Kitchen'],
        ['https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=640&q=80','Penthouse Views'],
        ['https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=640&q=80','Master Bedroom'],
        ['https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=640&q=80','Luxury Bathroom'],
        ['https://images.unsplash.com/photo-1600607687920-4e2a09cf159d?w=640&q=80','Private Balcony'],
        ['https://images.unsplash.com/photo-1571055107559-3e67626fa8be?w=640&q=80','Residents Lounge'],
      ]; foreach ($fallbackGallery as $img): ?>
      <div class="gallery-item">
        <img src="<?= $img[0] ?>" alt="<?= $img[1] ?>" loading="lazy" />
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<div class="section-divider"></div>

<!-- ══════════════════════════════════════
     SECTION 4 — HOW IT WORKS
══════════════════════════════════════ -->
<section id="how" class="how-section">
  <div class="section-tag reveal">Simple Process</div>
  <h2 class="section-title reveal reveal-delay-1" style="margin-bottom:.75rem;">How to Secure Your Unit</h2>
  <p class="section-sub reveal reveal-delay-2">Getting your new home is just three steps away.</p>
  <div class="steps">
    <div class="step reveal reveal-delay-1">
      <div class="step-num">1</div>
      <h3>Choose a Plan</h3>
      <p>Select an access plan below to unlock the full vacancy listings.</p>
    </div>
    <div class="step reveal reveal-delay-2">
      <div class="step-num">2</div>
      <h3>Pay via M-Pesa</h3>
      <p>Complete a secure M-Pesa STK push payment to gain instant access.</p>
    </div>
    <div class="step reveal reveal-delay-3">
      <div class="step-num">3</div>
      <h3>Pick Your Unit</h3>
      <p>Browse vacancies, view details, and reserve the apartment of your choice.</p>
    </div>
  </div>
</section>

<div class="section-divider"></div>

<!-- ══════════════════════════════════════
     SECTION 5 — PRICING / ACCESS PLANS
══════════════════════════════════════ -->
<section id="pricing" class="paywall-section">
  <div class="section-tag reveal">Access Plans</div>
  <h2 class="section-title reveal reveal-delay-1">Unlock Vacancy Listings</h2>
  <p class="section-sub reveal reveal-delay-2">Pay a one-time M-Pesa fee to browse all available units, floor plans, and pricing — then reserve your unit instantly.</p>
  <div class="pricing-cards">
    <?php if (!empty($plans)): ?>
      <?php foreach ($plans as $i => $plan):
        $d        = $i + 1;
        $featured = $plan['is_featured'];
        $features = is_array($plan['features']) ? $plan['features'] : [];
        $priceKES = number_format((float)$plan['price_kes']);
        $priceUSD = (float)$plan['price_usd'];
      ?>
      <div class="pricing-card <?= $featured ? 'featured' : '' ?> reveal reveal-delay-<?= $d ?>">
        <?php if ($featured): ?>
        <div class="popular-tag">Most Popular</div>
        <?php endif; ?>
        <div class="plan-name"><?= htmlspecialchars($plan['plan_name']) ?></div>
        <div class="price"><sup>KES</sup><?= $priceKES ?></div>
        <div class="price-note">One-time M-Pesa fee</div>
        <ul>
          <?php foreach ($features as $feat): ?>
          <li><span class="check">✓</span> <?= htmlspecialchars($feat) ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="payment.php?plan=<?= urlencode($plan['plan_key']) ?>&amount=<?= $priceUSD ?>"
           class="btn <?= $featured ? 'btn-gold' : 'btn-outline' ?> btn-full">
          Get <?= htmlspecialchars($plan['plan_name']) ?>
        </a>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <!-- Fallback static pricing -->
      <div class="pricing-card reveal reveal-delay-1">
        <div class="plan-name">Basic Access</div>
        <div class="price"><sup>KES</sup>650</div>
        <div class="price-note">One-time M-Pesa fee</div>
        <ul>
          <li><span class="check">✓</span> View available units</li>
          <li><span class="check">✓</span> See floor plans</li>
          <li><span class="check">✓</span> View monthly rent</li>
          <li><span class="check">✓</span> 24-hour access window</li>
        </ul>
        <a href="payment.php?plan=basic&amount=5" class="btn btn-outline btn-full">Get Basic Access</a>
      </div>
      <div class="pricing-card featured reveal reveal-delay-2">
        <div class="popular-tag">Most Popular</div>
        <div class="plan-name">Standard Access</div>
        <div class="price"><sup>KES</sup>1,950</div>
        <div class="price-note">One-time M-Pesa fee</div>
        <ul>
          <li><span class="check">✓</span> Everything in Basic</li>
          <li><span class="check">✓</span> Reserve a unit online</li>
          <li><span class="check">✓</span> 7-day access window</li>
          <li><span class="check">✓</span> Priority listing updates</li>
        </ul>
        <a href="payment.php?plan=standard&amount=15" class="btn btn-gold btn-full">Get Standard Access</a>
      </div>
      <div class="pricing-card reveal reveal-delay-3">
        <div class="plan-name">Premium Access</div>
        <div class="price"><sup>KES</sup>3,900</div>
        <div class="price-note">One-time M-Pesa fee</div>
        <ul>
          <li><span class="check">✓</span> Everything in Standard</li>
          <li><span class="check">✓</span> Virtual unit tours</li>
          <li><span class="check">✓</span> 30-day access window</li>
          <li><span class="check">✓</span> Early access to new listings</li>
        </ul>
        <a href="payment.php?plan=premium&amount=30" class="btn btn-outline btn-full">Get Premium Access</a>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="section-divider"></div>

<!-- ══════════════════════════════════════
     SECTION 6 — TESTIMONIALS
══════════════════════════════════════ -->
<?php if (!empty($testimonials)): ?>
<section class="testimonials-section">
  <div class="section-tag reveal">What Residents Say</div>
  <h2 class="section-title reveal reveal-delay-1" style="margin-bottom:2.5rem;">Loved by Our Tenants</h2>
  <div class="testimonials-grid">
    <?php foreach ($testimonials as $i => $t): $d = ($i % 4) + 1; ?>
    <div class="testimonial-card reveal reveal-delay-<?= $d ?>">
      <div class="testimonial-stars"><?= str_repeat('★', (int)$t['rating']) . str_repeat('☆', 5 - (int)$t['rating']) ?></div>
      <p class="testimonial-text">"<?= htmlspecialchars($t['review_text']) ?>"</p>
      <div class="testimonial-author">
        <div class="testimonial-avatar"><?= htmlspecialchars($t['avatar_initials'] ?? strtoupper(substr($t['tenant_name'], 0, 2))) ?></div>
        <div>
          <div class="testimonial-name"><?= htmlspecialchars($t['tenant_name']) ?></div>
          <?php if (!empty($t['unit'])): ?>
          <div class="testimonial-unit"><?= htmlspecialchars($t['unit']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<div class="section-divider"></div>
<?php endif; ?>

<!-- ══════════════════════════════════════
     SECTION 7 — NEWS / BLOG
══════════════════════════════════════ -->
<?php if (!empty($news)): ?>
<section class="news-section">
  <div style="text-align:center;margin-bottom:2.5rem;">
    <div class="section-tag reveal">Latest Updates</div>
    <h2 class="section-title reveal reveal-delay-1">News &amp; Announcements</h2>
  </div>
  <div class="news-grid">
    <?php foreach ($news as $i => $post): $d = ($i % 3) + 1; ?>
    <div class="news-card reveal reveal-delay-<?= $d ?>">
      <?php if (!empty($post['cover_image'])): ?>
      <div class="news-card-img">
        <img src="<?= htmlspecialchars($post['cover_image']) ?>"
             alt="<?= htmlspecialchars($post['title']) ?>" loading="lazy" />
      </div>
      <?php endif; ?>
      <div class="news-card-body">
        <div class="news-cat"><?= htmlspecialchars(ucfirst($post['category'])) ?></div>
        <div class="news-title"><?= htmlspecialchars($post['title']) ?></div>
        <?php if (!empty($post['excerpt'])): ?>
        <div class="news-excerpt"><?= htmlspecialchars($post['excerpt']) ?></div>
        <?php endif; ?>
        <div class="news-meta">
          By <?= htmlspecialchars($post['author_name']) ?> &nbsp;·&nbsp;
          <?= date('M j, Y', strtotime($post['published_at'])) ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<div class="section-divider"></div>
<?php endif; ?>

<!-- ══════════════════════════════════════
     SECTION 8 — MAP
══════════════════════════════════════ -->
<div class="map-section">
  <iframe
    src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3988.8195!2d36.8219!3d-1.2921!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMcKwMTcnMzEuNiJTIDM2wrA0OScxOC44IkU!5e0!3m2!1sen!2ske!4v1234567890"
    allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"
    title="<?= $siteName ?> location map">
  </iframe>
  <div class="map-overlay">
    <div class="map-pin">
      <div class="pin-icon">📍</div>
      <div class="pin-label"><?= $siteName ?></div>
      <div class="pin-addr"><?= $address ?></div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     SECTION 9 — CONTACT
══════════════════════════════════════ -->
<section id="contact" class="contact-section">
  <div class="contact-wrap">
    <!-- Left: info -->
    <div class="contact-info reveal reveal-left">
      <div class="section-tag" style="text-align:left;">Get in Touch</div>
      <h2>We're Here to Help You Find Your Home</h2>
      <p>Our team is available Monday to Saturday, 8am – 6pm. Reach out for unit availability, viewing appointments, or any other enquiries.</p>

      <div class="contact-detail">
        <span class="cd-icon">📍</span>
        <div>
          <div class="cd-label">Address</div>
          <div class="cd-value"><?= $address ?></div>
        </div>
      </div>
      <div class="contact-detail">
        <span class="cd-icon">📞</span>
        <div>
          <div class="cd-label">Phone / WhatsApp</div>
          <div class="cd-value"><a href="tel:<?= htmlspecialchars($phone) ?>" style="color:inherit;"><?= $phone ?></a></div>
        </div>
      </div>
      <div class="contact-detail">
        <span class="cd-icon">📧</span>
        <div>
          <div class="cd-label">Email</div>
          <div class="cd-value"><a href="mailto:<?= htmlspecialchars($email) ?>" style="color:inherit;"><?= $email ?></a></div>
        </div>
      </div>
      <div class="contact-detail">
        <span class="cd-icon">🕐</span>
        <div>
          <div class="cd-label">Office Hours</div>
          <div class="cd-value"><?= $hours ?></div>
        </div>
      </div>

      <div style="display:flex;gap:.75rem;margin-top:1.75rem;flex-wrap:wrap;">
        <a href="https://wa.me/<?= htmlspecialchars($whatsapp) ?>" class="btn btn-gold" target="_blank" rel="noopener">💬 WhatsApp Us</a>
        <a href="tel:<?= htmlspecialchars($phone) ?>" class="btn btn-outline">📞 Call Now</a>
      </div>

      <!-- Social links from DB -->
      <?php if (!empty($social)): ?>
      <div class="social-strip" style="padding:1.5rem 0 0;justify-content:flex-start;">
        <?php foreach ($social as $sl): ?>
        <a href="<?= htmlspecialchars($sl['url']) ?>" class="social-btn" target="_blank" rel="noopener">
          <?= htmlspecialchars($sl['icon_emoji'] ?? '') ?> <?= htmlspecialchars(ucfirst($sl['platform'])) ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right: PHP contact form -->
    <div class="contact-form-box reveal reveal-right">
      <h3>📩 Send Us a Message</h3>

      <?php if ($formSuccess): ?>
      <div class="form-alert success">
        ✅ Message sent! We'll get back to you within 24 hours.
      </div>
      <?php elseif ($formError): ?>
      <div class="form-alert error">
        ⚠️ <?= htmlspecialchars($formError) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="#contact" novalidate>
        <input type="hidden" name="cf_submit" value="1" />

        <div class="cf-group">
          <label for="cf_name">Full Name</label>
          <input type="text" id="cf_name" name="cf_name"
                 placeholder="Your full name"
                 value="<?= htmlspecialchars($_POST['cf_name'] ?? '') ?>"
                 required />
        </div>
        <div class="cf-group">
          <label for="cf_contact">Email or Phone</label>
          <input type="text" id="cf_contact" name="cf_contact"
                 placeholder="email@example.com or +254 7XX XXX XXX"
                 value="<?= htmlspecialchars($_POST['cf_contact'] ?? '') ?>"
                 required />
        </div>
        <div class="cf-group">
          <label for="cf_interest">I'm interested in</label>
          <select id="cf_interest" name="cf_interest">
            <?php foreach (['Viewing a unit','Rental pricing enquiry','Reservation process','General information','Other'] as $opt): ?>
            <option value="<?= htmlspecialchars($opt) ?>"
              <?= (($_POST['cf_interest'] ?? '') === $opt) ? 'selected' : '' ?>>
              <?= htmlspecialchars($opt) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cf-group">
          <label for="cf_message">Message</label>
          <textarea id="cf_message" name="cf_message"
                    placeholder="Tell us more about what you're looking for…"
                    required><?= htmlspecialchars($_POST['cf_message'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-gold btn-full">Send Message →</button>
      </form>
    </div>
  </div>
</section>

<div class="section-divider"></div>

<!-- ══════════════════════════════════════
     SECTION 10 — FAQ
══════════════════════════════════════ -->
<?php if (!empty($faqs)): ?>
<section id="faq" class="faq-section">
  <div style="text-align:center;margin-bottom:3rem;">
    <div class="section-tag reveal">Common Questions</div>
    <h2 class="section-title reveal reveal-delay-1">Frequently Asked Questions</h2>
    <p class="section-sub reveal reveal-delay-2">Everything you need to know before moving in.</p>
  </div>
  <div class="faq-grid">
    <?php foreach ($faqs as $i => $faq): ?>
    <div class="faq-item reveal" id="faq-<?= $faq['id'] ?>">
      <button class="faq-question" onclick="toggleFaq(<?= $faq['id'] ?>)" aria-expanded="false">
        <?= htmlspecialchars($faq['question']) ?>
        <span class="faq-chevron">▼</span>
      </button>
      <div class="faq-answer" id="faq-ans-<?= $faq['id'] ?>">
        <p><?= htmlspecialchars($faq['answer']) ?></p>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<div class="section-divider"></div>
<?php endif; ?>

<!-- ══════════════════════════════════════
     FOOTER
══════════════════════════════════════ -->
<footer style="background:#080808;border-top:1px solid var(--dark-border);padding:4rem 2rem 2rem;">
  <div class="footer-grid" style="display:grid;grid-template-columns:2fr repeat(3,1fr);gap:2.5rem;max-width:1180px;margin:0 auto 3rem;">
    <div class="footer-col">
      <div class="footer-logo" style="font-size:1.3rem;font-weight:800;color:var(--gold);letter-spacing:2.5px;text-transform:uppercase;margin-bottom:.9rem;"><?= $siteName ?></div>
      <p style="font-size:.85rem;color:var(--text-muted);line-height:1.75;">A premium residential building offering modern luxury living in the heart of Nairobi.</p>
      <!-- Social icons in footer -->
      <?php if (!empty($social)): ?>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1.25rem;">
        <?php foreach ($social as $sl): ?>
        <a href="<?= htmlspecialchars($sl['url']) ?>" target="_blank" rel="noopener"
           title="<?= htmlspecialchars(ucfirst($sl['platform'])) ?>"
           style="width:34px;height:34px;border-radius:50%;background:var(--dark-mid);border:1px solid var(--dark-border);display:flex;align-items:center;justify-content:center;font-size:1rem;transition:border-color .2s,transform .2s;"
           onmouseover="this.style.borderColor='var(--gold)';this.style.transform='translateY(-2px)'"
           onmouseout="this.style.borderColor='var(--dark-border)';this.style.transform='translateY(0)'">
          <?= htmlspecialchars($sl['icon_emoji'] ?? '🔗') ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="footer-col">
      <h4 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--text);margin-bottom:1.2rem;padding-bottom:.6rem;border-bottom:1px solid var(--dark-border);">Quick Links</h4>
      <ul style="list-style:none;">
        <?php foreach (['Home' => '#hero','About' => '#about','Gallery' => '#gallery','How It Works' => '#how','Access Plans' => '#pricing','Contact' => '#contact'] as $label => $href): ?>
        <li style="margin-bottom:.55rem;"><a href="<?= $href ?>" style="font-size:.85rem;color:var(--text-muted);transition:color .2s,padding-left .2s;" onmouseover="this.style.color='var(--gold)';this.style.paddingLeft='4px'" onmouseout="this.style.color='var(--text-muted)';this.style.paddingLeft='0'"><?= $label ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="footer-col">
      <h4 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--text);margin-bottom:1.2rem;padding-bottom:.6rem;border-bottom:1px solid var(--dark-border);">Services</h4>
      <ul style="list-style:none;">
        <li style="margin-bottom:.55rem;"><a href="apartments.php" style="font-size:.85rem;color:var(--text-muted);">View Vacancies</a></li>
        <li style="margin-bottom:.55rem;"><a href="payment.php?plan=standard&amount=15" style="font-size:.85rem;color:var(--text-muted);">Pay via M-Pesa</a></li>
        <li style="margin-bottom:.55rem;"><a href="payroll.php" style="font-size:.85rem;color:var(--text-muted);">Payroll Dashboard</a></li>
        <?php if (!empty($faqs)): ?>
        <li style="margin-bottom:.55rem;"><a href="#faq" style="font-size:.85rem;color:var(--text-muted);">FAQs</a></li>
        <?php endif; ?>
      </ul>
    </div>
    <div class="footer-col">
      <h4 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--text);margin-bottom:1.2rem;padding-bottom:.6rem;border-bottom:1px solid var(--dark-border);">Contact</h4>
      <p style="font-size:.85rem;color:var(--text-muted);line-height:1.75;"><?= $address ?></p>
      <p style="margin-top:.75rem;font-size:.85rem;"><a href="tel:<?= htmlspecialchars($phone) ?>" style="color:var(--text-muted);"><?= $phone ?></a></p>
      <p style="font-size:.85rem;"><a href="mailto:<?= htmlspecialchars($email) ?>" style="color:var(--text-muted);"><?= $email ?></a></p>
    </div>
  </div>
  <div style="border-top:1px solid var(--dark-border);padding-top:1.5rem;max-width:1180px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;">
    <p style="font-size:.78rem;color:var(--text-faint);">&copy; <?= date('Y') ?> <?= $siteName ?>. All rights reserved.</p>
    <p style="font-size:.78rem;color:var(--text-faint);">Built with ❤️ for luxury living in Nairobi.</p>
  </div>
</footer>

<!-- Back to top -->
<button id="back-to-top" aria-label="Back to top" onclick="window.scrollTo({top:0,behavior:'smooth'})">↑</button>

<!-- Toast -->
<div class="toast" id="toast"></div>

<?php if (!$dbOk): ?>
<!-- DB connection notice (only visible in development) -->
<div style="position:fixed;bottom:1rem;left:1rem;background:#1a1a1a;border:1px solid #e05c5c;border-radius:8px;padding:.75rem 1rem;font-size:.8rem;color:#e05c5c;z-index:999;max-width:320px;">
  ⚠️ Database unavailable — showing static fallback content.
  <br><a href="api/README.md" style="color:#e05c5c;text-decoration:underline;">Setup guide →</a>
</div>
<?php endif; ?>

<script src="script.js"></script>
<script>
  /* ── Nav drawer ── */
  const toggle   = document.getElementById('nav-toggle');
  const drawer   = document.getElementById('nav-drawer');
  const backdrop = document.getElementById('nav-backdrop');
  function openDrawer()  { toggle.classList.add('open');    drawer.classList.add('open');    toggle.setAttribute('aria-expanded','true');  document.body.style.overflow='hidden'; }
  function closeDrawer() { toggle.classList.remove('open'); drawer.classList.remove('open'); toggle.setAttribute('aria-expanded','false'); document.body.style.overflow=''; }
  toggle.addEventListener('click', () => drawer.classList.contains('open') ? closeDrawer() : openDrawer());
  backdrop.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', e => { if (e.key==='Escape') closeDrawer(); });

  /* ── Navbar scroll shadow + active nav ── */
  const navbar   = document.getElementById('navbar');
  const sections = ['hero','about','gallery','how','pricing','faq','contact'].filter(id => document.getElementById(id));
  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 40);
    document.getElementById('back-to-top').classList.toggle('visible', window.scrollY > 400);
    let current = sections[0];
    sections.forEach(id => {
      const el = document.getElementById(id);
      if (el && window.scrollY >= el.offsetTop - 110) current = id;
    });
    document.querySelectorAll('.nav-links a[data-section]').forEach(a => {
      a.classList.toggle('nav-active', a.getAttribute('data-section') === current);
    });
  }, { passive: true });

  /* Add nav-active colour rule */
  const ns = document.createElement('style');
  ns.textContent = '.nav-links a.nav-active { color: var(--gold) !important; }';
  document.head.appendChild(ns);

  /* ── Scroll reveal ── */
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
  }, { threshold: 0.1 });
  document.querySelectorAll('.reveal,.reveal-left,.reveal-right').forEach(el => obs.observe(el));

  /* ── Animate hero counters (values already set by PHP, just animate visually) ── */
  function animateCounter(id) {
    const el     = document.getElementById(id);
    if (!el) return;
    const target = parseInt(el.textContent) || 0;
    let   n      = 0;
    const step   = Math.ceil(target / 40);
    el.textContent = '0';
    const t = setInterval(() => {
      n = Math.min(n + step, target);
      el.textContent = n;
      if (n >= target) clearInterval(t);
    }, 35);
  }
  const heroObs = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting) {
      ['cnt-units','cnt-avail','cnt-floors'].forEach(animateCounter);
      heroObs.disconnect();
    }
  }, { threshold: 0.3 });
  const heroEl = document.getElementById('hero');
  if (heroEl) heroObs.observe(heroEl);

  /* ── FAQ accordion ── */
  function toggleFaq(id) {
    const item = document.getElementById('faq-' + id);
    const btn  = item.querySelector('.faq-question');
    const ans  = item.querySelector('.faq-answer');
    const open = item.classList.contains('open');
    // Close all
    document.querySelectorAll('.faq-item.open').forEach(el => {
      el.classList.remove('open');
      el.querySelector('.faq-question').setAttribute('aria-expanded','false');
    });
    // Open clicked (unless it was already open)
    if (!open) {
      item.classList.add('open');
      btn.setAttribute('aria-expanded','true');
    }
  }

  <?php if ($formSuccess): ?>
  /* Scroll to contact form after successful submission */
  window.addEventListener('load', () => {
    document.getElementById('contact')?.scrollIntoView({ behavior:'smooth' });
  });
  <?php endif; ?>
</script>
</body>
</html>
