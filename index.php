<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Old Union | South African Cooperative Management Company</title>
    <meta name="description" content="Old Union is an investment gateway connecting global investors to South African township markets.">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">

    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "Old Union Co-operative Management",
      "url": "https://www.oldunion.co.za",
      "logo": "https://www.oldunion.co.za/assets/images/logo/icon.png",
      "sameAs": ["https://www.x.com/oldunionza","https://www.linkedin.com/company/oldunion/"],
      "contactPoint": [{"@type": "ContactPoint","telephone": "+27 86 012 3456","contactType": "Customer Service","areaServed": "ZA","availableLanguage": "English"}]
    }
    </script>

<style>
/* ══════════════════════════════════════════════════════
   1. TOKENS
══════════════════════════════════════════════════════ */
:root {
    --ink:          #07111f;
    --navy:         #0c1e33;
    --navy-deep:    #060f1a;
    --navy-mid:     #122844;
    --cream:        #f2ead8;
    --cream-light:  #f9f5ec;
    --gold:         #a68a4a;
    --gold-light:   #c8a96a;
    --gold-dim:     rgba(166,138,74,.15);
    --gold-rule:    rgba(166,138,74,.4);
    --white:        #ffffff;
    --off-white:    #f7f3eb;
    --charcoal:     #1c2635;
    --slate:        #6b7a8d;
    --rule:         rgba(255,255,255,.08);
    --rule-dark:    rgba(7,17,31,.1);
    --serif:        'Cormorant Garamond', Georgia, serif;
    --sans:         'DM Sans', system-ui, sans-serif;
    --mono:         'DM Mono', 'Courier New', monospace;
    --transition:   0.35s cubic-bezier(.4,0,.2,1);
}

/* ══════════════════════════════════════════════════════
   2. RESET & BASE
══════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: var(--sans);
    line-height: 1.6;
    color: var(--white);
    overflow: hidden;
    background: var(--navy-deep);
}

/* ══════════════════════════════════════════════════════
   3. SNAP CONTAINER
══════════════════════════════════════════════════════ */
.snap-container {
    height: 100vh;
    overflow-y: scroll;
    scroll-snap-type: y mandatory;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.snap-container::-webkit-scrollbar { display: none; }

section {
    height: 100vh;
    scroll-snap-align: start;
    position: relative;
    display: flex;
    align-items: center;
    overflow: hidden;
}

/* Gold-and-navy institutional rule between sections */
section::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 2px;
    background: linear-gradient(90deg, transparent 0%, var(--gold) 30%, var(--gold-light) 50%, var(--gold) 70%, transparent 100%);
    z-index: 10;
    opacity: 0.6;
}

/* ══════════════════════════════════════════════════════
   4. PRESERVED HERO SECTION
══════════════════════════════════════════════════════ */
.hero {
    position: relative;
    display: flex;
    align-items: flex-start;
    padding: 0 2rem;
    background: rgba(0,0,0,0.8);
    background-image: url('app/assets/images/home/hero.jpg');
    background-position: center;
    background-size: cover;
}
.hero::before {
    content: '';
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    background: var(--navy-deep);
    opacity: 0.65;
    z-index: 1;
}
.hero__image {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    object-fit: cover;
    filter: grayscale(100%);
    z-index: 0;
}
.hero__content {
    position: relative;
    width: 60%;
    color: white;
    z-index: 2;
    padding: 0 4%;
    animation: fadeInUp 1s ease-out both;
    height: 100%;
    background: linear-gradient(
        to bottom,
        rgba(6,15,26,.85) 13em,
        rgba(6,15,26,.85) 13em,
        rgba(0,0,0,0.45) 0%
    );
}
.hero__body { margin-top: 2em; }

/* Brand mark */
.brand { margin: 3rem 0; }
.brand__title {
    font-family: var(--serif);
    font-size: clamp(2.2rem, 6vw, 3rem);
    font-weight: 300;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--white);
    margin-bottom: .2rem;
}
.brand__title em {
    font-style: normal;
    color: var(--gold-light);
}
.brand__subtitle {
    font-family: var(--mono);
    font-size: .72rem;
    font-weight: 300;
    letter-spacing: .25em;
    text-transform: uppercase;
    color: rgba(255,255,255,.4);
    margin-top: .2rem;
}

/* Hero headings */
.hero__heading {
    font-family: var(--serif);
    font-size: clamp(1.8rem, 4.5vw, 2.6rem);
    font-weight: 300;
    line-height: 1.25;
    letter-spacing: -.01em;
    width: 85%;
    color: var(--white);
    margin-bottom: 1.25rem;
    margin-top: 2em;
}
.hero__text {
    font-family: var(--sans);
    font-size: clamp(.92rem, 2.2vw, 1.05rem);
    font-weight: 300;
    line-height: 1.75;
    color: rgba(255,255,255,.72);
    width: 72%;
    margin-bottom: 2.25rem;
}

/* Hero CTA buttons */
.hero-btn-row { display: flex; gap: 1rem; flex-wrap: wrap; }
.hero-btn {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .8rem 1.75rem;
    font-family: var(--sans);
    font-size: .85rem;
    font-weight: 500;
    letter-spacing: .08em;
    text-transform: uppercase;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all var(--transition);
}
.hero-btn--primary {
    background: var(--gold);
    color: var(--navy-deep);
}
.hero-btn--primary:hover {
    background: var(--gold-light);
    transform: translateY(-2px);
}
.hero-btn--outline {
    background: transparent;
    color: var(--white);
    border: 1px solid rgba(255,255,255,.3);
}
.hero-btn--outline:hover {
    border-color: var(--gold);
    color: var(--gold-light);
}

/* ══════════════════════════════════════════════════════
   5. SECTION COMMON COMPONENTS
══════════════════════════════════════════════════════ */
.section-eyebrow {
    font-family: var(--mono);
    font-size: .68rem;
    font-weight: 400;
    letter-spacing: .3em;
    text-transform: uppercase;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: .75rem;
}
.section-eyebrow::before {
    content: '';
    display: block;
    width: 32px;
    height: 1px;
    background: var(--gold);
}
.section-eyebrow--dark { color: var(--gold); }
.section-eyebrow--cream { color: rgba(7,17,31,.45); }
.section-eyebrow--cream::before { background: rgba(7,17,31,.3); }

.section-headline {
    font-family: var(--serif);
    font-weight: 300;
    letter-spacing: -.02em;
    line-height: 1.1;
}

.gold-rule {
    width: 100%;
    height: 1px;
    background: linear-gradient(90deg, var(--gold-rule) 0%, rgba(166,138,74,.08) 100%);
    margin: 0 0 .85rem;
}
.gold-rule--full { background: var(--gold-rule); }

/* ══════════════════════════════════════════════════════
   6. SECTION 1 — NUMBERS (The Platform)
══════════════════════════════════════════════════════ */
#section-numbers {
    background: var(--navy-deep);
    padding: 0 7vw;
}

.numbers-layout {
    max-width: 1300px;
    margin: 0 auto;
    width: 100%;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 6vw;
    align-items: center;
}

.numbers-left { padding-right: 4vw; border-right: 1px solid var(--rule); }

.numbers-headline {
    font-family: var(--serif);
    font-size: clamp(2.8rem, 5vw, 4.5rem);
    font-weight: 300;
    line-height: 1.05;
    letter-spacing: -.02em;
    color: var(--white);
    margin-bottom: 1.5rem;
}
.numbers-headline em {
    font-style: italic;
    color: var(--gold-light);
}

.numbers-body {
    font-size: .92rem;
    font-weight: 300;
    color: rgba(255,255,255,.55);
    line-height: 1.75;
    max-width: 420px;
    margin-bottom: 2rem;
}

.numbers-cta {
    display: inline-flex;
    align-items: center;
    gap: .6rem;
    font-family: var(--mono);
    font-size: .72rem;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--gold-light);
    text-decoration: none;
    cursor: pointer;
    border: none;
    background: none;
    padding: 0;
    transition: gap var(--transition);
}
.numbers-cta:hover { gap: 1rem; }
.numbers-cta i { font-size: .65rem; }

.numbers-right { padding-left: 2vw; }

.stat-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
}

.stat-item {
    padding: 2rem 1.5rem;
    border-right: 1px solid var(--rule);
    border-bottom: 1px solid var(--rule);
    position: relative;
    overflow: hidden;
    transition: background var(--transition);
}
.stat-item:nth-child(even) { border-right: none; }
.stat-item:nth-child(3),
.stat-item:nth-child(4) { border-bottom: none; }
.stat-item::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 2px;
    background: linear-gradient(90deg, var(--gold), transparent);
    opacity: 0;
    transition: opacity var(--transition);
}
.stat-item:hover::before { opacity: 1; }
.stat-item:hover { background: rgba(166,138,74,.04); }

.stat-number {
    font-family: var(--serif);
    font-size: clamp(2.4rem, 4vw, 3.8rem);
    font-weight: 300;
    color: var(--white);
    line-height: 1;
    margin-bottom: .35rem;
    letter-spacing: -.02em;
}
.stat-number sup {
    font-size: .45em;
    color: var(--gold-light);
    vertical-align: super;
    font-weight: 300;
}

.stat-label {
    font-family: var(--mono);
    font-size: .65rem;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: rgba(255,255,255,.4);
    line-height: 1.4;
}

.stat-desc {
    font-size: .78rem;
    color: rgba(255,255,255,.28);
    margin-top: .4rem;
    font-weight: 300;
    line-height: 1.5;
}

/* ══════════════════════════════════════════════════════
   7. SECTION 2 — INSTRUMENTS
══════════════════════════════════════════════════════ */
#section-instruments {
    background: var(--cream-light);
    color: var(--ink);
    padding: 0 7vw;
}

.instruments-layout {
    max-width: 1300px;
    margin: 0 auto;
    width: 100%;
}

.instruments-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid var(--rule-dark);
    gap: 2rem;
    flex-wrap: wrap;
}

.instruments-headline {
    font-family: var(--serif);
    font-size: clamp(2.4rem, 4.5vw, 3.8rem);
    font-weight: 300;
    line-height: 1.05;
    letter-spacing: -.02em;
    color: var(--ink);
}
.instruments-headline em {
    font-style: italic;
    color: var(--gold);
}

.instruments-note {
    max-width: 320px;
    font-size: .82rem;
    color: var(--slate);
    line-height: 1.65;
    font-weight: 300;
    text-align: right;
}

.instruments-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
    border: 1px solid rgba(7,17,31,.1);
}

.instrument-card {
    padding: 2.5rem 2rem;
    border-right: 1px solid rgba(7,17,31,.08);
    position: relative;
    transition: background var(--transition);
    background: transparent;
}
.instrument-card:last-child { border-right: none; }
.instrument-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 3px;
    background: var(--gold);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform var(--transition);
}
.instrument-card:hover { background: rgba(166,138,74,.04); }
.instrument-card:hover::before { transform: scaleX(1); }

.instrument-index {
    font-family: var(--serif);
    font-size: 3.5rem;
    font-weight: 300;
    color: rgba(7,17,31,.07);
    line-height: 1;
    margin-bottom: .5rem;
    letter-spacing: -.04em;
}

.instrument-icon {
    width: 40px;
    height: 40px;
    border: 1px solid rgba(166,138,74,.35);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.25rem;
    color: var(--gold);
    font-size: .9rem;
    transition: all var(--transition);
}
.instrument-card:hover .instrument-icon {
    background: var(--gold);
    color: var(--white);
    border-color: var(--gold);
}

.instrument-name {
    font-family: var(--serif);
    font-size: 1.55rem;
    font-weight: 400;
    color: var(--ink);
    letter-spacing: -.01em;
    line-height: 1.2;
    margin-bottom: .75rem;
}

.instrument-desc {
    font-size: .82rem;
    color: var(--slate);
    line-height: 1.7;
    font-weight: 300;
    margin-bottom: 1.5rem;
}

.instrument-tags {
    display: flex;
    flex-direction: column;
    gap: .4rem;
}

.instrument-tag {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .72rem;
    color: var(--slate);
    font-family: var(--mono);
    letter-spacing: .05em;
}
.instrument-tag::before {
    content: '';
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: var(--gold);
    flex-shrink: 0;
}

.instrument-cta {
    position: absolute;
    bottom: 2rem;
    right: 2rem;
    width: 36px;
    height: 36px;
    border: 1px solid rgba(166,138,74,.3);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gold);
    font-size: .8rem;
    text-decoration: none;
    transition: all var(--transition);
    cursor: pointer;
}
.instrument-card:hover .instrument-cta {
    background: var(--gold);
    color: var(--white);
    border-color: var(--gold);
}

/* ══════════════════════════════════════════════════════
   8. SECTION 3 — PROCESS
══════════════════════════════════════════════════════ */
#section-process {
    background: var(--charcoal);
    padding: 0 7vw;
}

.process-layout {
    max-width: 1300px;
    margin: 2rem auto;
    width: 100%;
}

.process-header {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4vw;
    margin: 2rem 0;
    align-items: end;
}

.process-headline {
    font-family: var(--serif);
    font-size: clamp(2.4rem, 4.5vw, 3.8rem);
    font-weight: 300;
    line-height: 1.05;
    letter-spacing: -.02em;
    color: var(--white);
}
.process-headline em {
    font-style: italic;
    color: var(--gold-light);
}

.process-sub {
    font-size: .88rem;
    font-weight: 300;
    color: rgba(255,255,255,.45);
    line-height: 1.75;
    padding-top: .5rem;
    border-top: 1px solid var(--rule);
}

.process-steps {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
    position: relative;
}

/* Connecting line behind step numbers */
.process-steps::before {
    content: '';
    position: absolute;
    top: 23px;
    left: calc(12.5% + 16px);
    width: calc(75% - 32px);
    height: 1px;
    background: linear-gradient(90deg, var(--gold-rule) 0%, var(--gold-rule) 100%);
    z-index: 0;
}

.process-step {
    padding: 0 1.5rem 0 0;
    position: relative;
    z-index: 1;
}

.process-step-num {
    width: 46px;
    height: 46px;
    border: 1px solid var(--gold-rule);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--mono);
    font-size: .75rem;
    font-weight: 300;
    letter-spacing: .1em;
    color: var(--gold);
    background: var(--charcoal);
    margin-bottom: 1.5rem;
    transition: all var(--transition);
    position: relative;
    z-index: 1;
}

.process-step:hover .process-step-num {
    background: var(--gold);
    color: var(--navy-deep);
    border-color: var(--gold);
}

.process-step-label {
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: .65rem;
}

.process-step-title {
    font-family: var(--serif);
    font-size: 1.3rem;
    font-weight: 400;
    color: var(--white);
    line-height: 1.2;
    margin-bottom: .65rem;
}

.process-step-body {
    font-size: .8rem;
    font-weight: 300;
    color: rgba(255,255,255,.4);
    line-height: 1.7;
}

/* Verification strip at bottom of process section */
.process-verify {
    margin-top: 1.5rem;
    padding-top: 1.75rem;
    border-top: 1px solid var(--rule);
    display: flex;
    align-items: center;
    gap: 3rem;
    flex-wrap: wrap;
}

.verify-item {
    display: flex;
    align-items: center;
    gap: .65rem;
    font-family: var(--mono);
    font-size: .65rem;
    letter-spacing: .15em;
    text-transform: uppercase;
    color: rgba(255,255,255,.35);
}
.verify-item i {
    color: var(--gold);
    font-size: .75rem;
}

/* ══════════════════════════════════════════════════════
   9. SECTION 4 — CTA / COMMITMENT
══════════════════════════════════════════════════════ */
#section-cta {
    background: var(--ink);
    padding: 0 7vw;
    position: relative;
    overflow: hidden;
}

/* Subtle decorative background texture */
#section-cta::before {
    content: '';
    position: absolute;
    top: -200px;
    right: -200px;
    width: 600px;
    height: 600px;
    border-radius: 50%;
    border: 1px solid rgba(166,138,74,.07);
    pointer-events: none;
}
#section-cta::after {
    content: none;
}

.cta-inner-rule {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 2px;
    background: linear-gradient(90deg, transparent 0%, var(--gold) 30%, var(--gold-light) 50%, var(--gold) 70%, transparent 100%);
    opacity: 0.6;
}

.cta-layout {
    max-width: 1300px;
    margin: 0 auto;
    width: 100%;
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    gap: 6vw;
}

.cta-left {}

.cta-pre {
    font-family: var(--serif);
    font-size: clamp(1rem, 2vw, 1.3rem);
    font-weight: 300;
    font-style: italic;
    color: rgba(255,255,255,.35);
    margin-bottom: .75rem;
}

.cta-headline {
    font-family: var(--serif);
    font-size: clamp(2.8rem, 5.5vw, 5.5rem);
    font-weight: 300;
    line-height: 1;
    letter-spacing: -.03em;
    color: var(--white);
    margin-bottom: 1.5rem;
}
.cta-headline em {
    font-style: italic;
    color: var(--gold-light);
}

.cta-statement {
    font-size: .9rem;
    font-weight: 300;
    color: rgba(255,255,255,.45);
    line-height: 1.75;
    max-width: 540px;
    margin-bottom: 2.5rem;
}

.cta-legal {
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .12em;
    color: rgba(255,255,255,.2);
    line-height: 1.6;
    margin-top: .5rem;
    max-width: 480px;
}

.cta-right {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    flex-shrink: 0;
    min-width: 240px;
}

.cta-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
    padding: 1.1rem 1.5rem;
    font-family: var(--sans);
    font-size: .82rem;
    font-weight: 500;
    letter-spacing: .06em;
    text-transform: uppercase;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all var(--transition);
    white-space: nowrap;
}
.cta-btn i { font-size: .75rem; opacity: .6; transition: all var(--transition); }
.cta-btn:hover i { opacity: 1; transform: translateX(3px); }

.cta-btn--gold {
    background: var(--gold);
    color: var(--navy-deep);
}
.cta-btn--gold:hover { background: var(--gold-light); }

.cta-btn--outline {
    background: transparent;
    color: rgba(255,255,255,.7);
    border: 1px solid rgba(255,255,255,.15);
}
.cta-btn--outline:hover {
    border-color: var(--gold-rule);
    color: var(--gold-light);
}

/* CTA right: featured provinces badge */
.provinces-strip {
    margin-top: 2rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--rule);
}
.provinces-label {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: rgba(255,255,255,.25);
    margin-bottom: .6rem;
}
.provinces-list {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
}
.province-pill {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .1em;
    color: rgba(255,255,255,.3);
    padding: .2rem .5rem;
    border: 1px solid rgba(255,255,255,.08);
}

/* ══════════════════════════════════════════════════════
   10. NAVIGATION DOTS
══════════════════════════════════════════════════════ */
.nav-dots {
    position: fixed;
    right: 2.5rem;
    top: 50%;
    transform: translateY(-50%);
    z-index: 100;
    display: flex;
    flex-direction: column;
    gap: .85rem;
}
.nav-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: rgba(255,255,255,.2);
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
}
.nav-dot::after {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    border: 1px solid transparent;
    transition: border-color .3s;
}
.nav-dot.active {
    background: var(--gold);
    transform: scale(1.2);
}
.nav-dot.active::after { border-color: rgba(166,138,74,.4); }

/* ══════════════════════════════════════════════════════
   11. ANIMATIONS
══════════════════════════════════════════════════════ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(2rem); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ══════════════════════════════════════════════════════
   12. RESPONSIVE
══════════════════════════════════════════════════════ */
@media (max-width: 1200px) {
    .hero_content { height: 100vh;}
    .instruments-grid { grid-template-columns: 1fr; }
    .instrument-card { border-right: none; border-bottom: 1px solid rgba(7,17,31,.08); }
    .instrument-card:last-child { border-bottom: none; }
    .process-steps { grid-template-columns: repeat(2, 1fr); gap: 2rem 3rem; }
    .process-steps::before { display: none; }
    .cta-layout { grid-template-columns: 1fr; }
    .cta-right { flex-direction: row; flex-wrap: wrap; }
    .provinces-strip { display: none; }
}

@media (max-width: 1024px) {
    .snap-container { height: auto; overflow-y: visible; scroll-snap-type: none; }
    section { height: auto; min-height: 100vh; scroll-snap-align: none; }
    .hero__content { width: 100vw; background: linear-gradient(to bottom, rgba(6,15,26,.9) 14em, rgba(6,15,26,.9) 14em, rgba(0,0,0,.5) 0%); }
    .hero__body { margin-top: 6em; }
    .brand { margin: 5em 0; }
    .numbers-layout { grid-template-columns: 1fr; gap: 3rem; }
    .numbers-left { border-right: none; border-bottom: 1px solid var(--rule); padding-right: 0; padding-bottom: 2rem; }
    .numbers-right { padding-left: 0; }
    .process-header { grid-template-columns: 1fr; }
    .nav-dots { display: none; }
    body { overflow-y: scroll; }
    section { padding: 4rem 7vw; }
    #section-numbers, #section-instruments, #section-process, #section-cta { padding: 4rem 7vw; align-items: flex-start; }
}

@media (max-width: 768px) {
    .hero { padding: 0; }
    .hero__content { width: 100%; height: 100vh; background: linear-gradient(to bottom, rgba(6,15,26,.9) 13rem, rgba(6,15,26,.9) 13rem, rgba(0,0,0,.5) 0%); }
    .brand { margin: 7em 0; }
    .hero__text, .hero__heading { width: 100%; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .instruments-grid { grid-template-columns: 1fr; }
    .process-steps { grid-template-columns: 1fr; }
    .process-steps::before { display: none; }
    .cta-layout { grid-template-columns: 1fr; }
    .instruments-header { flex-direction: column; align-items: flex-start; }
    .instruments-note { text-align: left; max-width: 100%; }
}

@media (max-width: 600px) {
    .hero__content { padding: 1rem; }
    .brand { margin: 2em 0; }
    .stat-grid { grid-template-columns: 1fr; }
    .hero-btn-row { flex-direction: column; }
    .cta-right { flex-direction: column; }
    .process-steps { grid-template-columns: 1fr; }
}


</style>
</head>
<?php include('public/header.php'); ?>
<body>

<!-- ════════════════════════════════════════════════════
     SNAP CONTAINER
════════════════════════════════════════════════════ -->
<div class="snap-container" id="snapContainer">

    <!-- ══ SECTION 0 — HERO (PRESERVED) ══ -->
    <section class="hero" id="section-1">
        <div class="hero__content">
            <div class="brand">
                <div class="brand__title"><em>Old</em> Union</div>
                <div class="brand__subtitle">Cooperative Management Company · Est. South Africa</div>
            </div>
            <div class="hero__body">
                <h1 class="hero__heading">
                    Connecting Capital Markets to Township Economy.
                </h1>
                <p class="hero__text">
                    Old Union is a premium investment community where impact investors
                    from anywhere in the world can discover, connect with, and invest in 
                    the best impact founders in South Africa.
                </p>
                <div class="hero-btn-row">
                    <button class="hero-btn hero-btn--primary" id="cta-Btn">
                        Start Investing &nbsp;<i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <!--<button class="hero-btn hero-btn--outline" id="cta-Btn2">
                        List Your Business
                    </button>-->
                </div>
            </div>
        </div>
    </section>

    <!-- ══ SECTION 1 — PROCESS ══ -->
    <section id="section-process">
        <div class="process-layout">

            <div class="process-header">
                <div>
                    <div class="section-eyebrow section-eyebrow--dark">How Capital Moves</div>
                    <h2 class="process-headline">
                        Our investment<br>
                        process is <em>simple.</em>
                    </h2>
                </div>
                <p class="process-sub">
                    From registration through to payout, every step is structured, transparent, 
                    and platform-administered. Contributors maintain full visibility of their investments. 
                    Businesses retain operational control.
                </p>
            </div>

            <div class="process-steps">

                <div class="process-step">
                    <div class="process-step-num">01</div>
                    <div class="process-step-label">Register & Verify</div>
                    <h3 class="process-step-title">Create an Account</h3>
                    <p class="process-step-body">
                        Register your account and complete our KYC process. Contributors complete identity verification; 
                        businesses undergo a comprehensive company audit including CIPC registration and director ID checks.
                    </p>
                </div>

                <div class="process-step">
                    <div class="process-step-num">02</div>
                    <div class="process-step-label">Discover & Evaluate</div>
                    <h3 class="process-step-title">Browse Opportunities</h3>
                    <p class="process-step-body">
                        Explore verified businesses across nine provinces. Review financials, milestones, pitch documents, 
                        and deal terms — then ask direct questions through the campaign Q&A. All information is auditable.
                    </p>
                </div>

                <div class="process-step">
                    <div class="process-step-num">03</div>
                    <div class="process-step-label">Contribute</div>
                    <h3 class="process-step-title">Deploy Capital</h3>
                    <p class="process-step-body">
                        Select your contribution amount, review the full investment agreement, and complete payment via your 
                        Old Union wallet or bank EFT. Your position is recorded on the platform immediately.
                    </p>
                </div>

                <div class="process-step">
                    <div class="process-step-num">04</div>
                    <div class="process-step-label">Receive Returns</div>
                    <h3 class="process-step-title">Track & Earn</h3>
                    <p class="process-step-body">
                        Monitor campaign updates, financial disclosures, and payout history through your portfolio dashboard. 
                        Returns are distributed directly to your Old Union wallet on the agreed schedule.
                    </p>
                </div>

            </div>

            <div class="process-verify">
                <div class="verify-item"><i class="fa-solid fa-shield"></i> CIPC-Verified Businesses</div>
                <div class="verify-item"><i class="fa-solid fa-scale-balanced"></i> SA Private Placement Compliant</div>
                <div class="verify-item"><i class="fa-solid fa-lock"></i> Secure Escrow Management</div>
                <div class="verify-item"><i class="fa-solid fa-file-contract"></i> Legally Structured Agreements</div>
                <div class="verify-item"><i class="fa-solid fa-rotate-left"></i> Full Refund if Minimum Not Met</div>
            </div>

        </div>
    </section>

    <!-- ══ SECTION 2 — NUMBERS ══ -->
    <section id="section-numbers">
        <div class="numbers-layout">

            <div class="numbers-left">
                <div class="section-eyebrow section-eyebrow--dark">The Platform</div>
                <h2 class="numbers-headline">
                    Mobilizing global<br>
                    impact <em>capital.</em>
                </h2>
                <p class="numbers-body">
                    We are building a large network of financial partners founders can raise money from
                    by mobilizing impact investors from around the world into a single platform.
                </p>
                <button class="numbers-cta" id="learnBtn">
                    How it Works <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>

            <div class="numbers-right">
                <div class="stat-grid">
                    <div class="stat-item">
                        <div class="stat-number">R 127<sup>M+</sup></div>
                        <div class="stat-label">Capital Deployed</div>
                        <div class="stat-desc">Across all active and closed campaigns on the platform</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">340<sup>+</sup></div>
                        <div class="stat-label">Verified Businesses</div>
                        <div class="stat-desc">Screened, KYC-cleared and listed on the platform</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">9</div>
                        <div class="stat-label">Provinces Covered</div>
                        <div class="stat-desc">From the Western Cape to Limpopo — all nine provinces</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">50</div>
                        <div class="stat-label">Max Contributors</div>
                        <div class="stat-desc">Per raise, in compliance with SA private placement law</div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- ══ SECTION 2 — INSTRUMENTS ══ -->
    <section id="section-instruments">
        <div class="instruments-layout">

            <div class="instruments-header">
                <div>
                    <div class="section-eyebrow section-eyebrow--cream">The Investment Case</div>
                    <h2 class="instruments-headline">
                        Structured.<br>
                        Verified. <em>Ready.</em>
                    </h2>
                </div>
                <p class="instruments-note">
                    Every opportunity on Old Union is sourced, verified, and structured before it reaches you.
                </p>
            </div>

            <div class="instruments-grid">

                <!-- Revenue Share -->
                <div class="instrument-card">
                    <!--<div class="instrument-index">01</div>-->
                    <!--<div class="instrument-icon"><i class="fa-solid fa-shield"></i></div>-->
                    <h3 class="instrument-name">Curated Opportunities</h3>
                    <p class="instrument-desc">
                            We do not operate an open marketplace. Every opportunity is sourced, evaluated, 
                            and selected based on its ability to generate real, repeatable cash flow.
                    </p>
                    <div class="instrument-tags">
                        <div class="instrument-tag">No open listings</div>
                        <div class="instrument-tag">Quality over volume</div>
                        <div class="instrument-tag">Real asset focus</div>
                        <!--<div class="instrument-tag">No equity transfer</div>-->
                    </div>
                    <a class="instrument-cta" href="/app/discover/"><i class="fa-solid fa-arrow-right"></i></a>
                </div>

                <!-- Cooperative Membership -->
                <div class="instrument-card">
                    <!--<div class="instrument-index">02</div>-->
                    <!--<div class="instrument-icon"><i class="fa-solid fa-people-roof"></i></div>-->
                    <h3 class="instrument-name">Verified Operators</h3>
                    <p class="instrument-desc">
                            We verify the companies behind every deal — their track record, operations, 
                            and ability to deliver — not just the opportunity itself.
                    </p>
                    <div class="instrument-tags">
                        <div class="instrument-tag">Company-level due diligence</div>
                        <div class="instrument-tag">Operational validation</div>
                        <div class="instrument-tag">Ongoing monitoring</div>
                        <!--<div class="instrument-tag">Ideal for township businesses</div>-->
                    </div>
                    <a class="instrument-cta" href="/app/discover/"><i class="fa-solid fa-arrow-right"></i></a>
                </div>

                <!-- Fixed Return Loan -->
                <div class="instrument-card">
                    <!--<div class="instrument-index">03</div>-->
                    <!--<div class="instrument-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>-->
                    <h3 class="instrument-name">Deal-Ready Structures</h3>
                    <p class="instrument-desc">
                            Every campaign is fully structured as a standalone SPV — with defined terms, 
                            risk, and return logic — before it is made available.
                    </p>
                    <div class="instrument-tags">
                        <div class="instrument-tag">Legally structured SPVs</div>
                        <div class="instrument-tag">Clear return models</div>
                        <div class="instrument-tag">Immediate deployment</div>
                        <!--<div class="instrument-tag">Governed by loan agreement</div>-->
                    </div>
                    <a class="instrument-cta" href="/app/discover/"><i class="fa-solid fa-arrow-right"></i></a>
                </div>

            </div>

        </div>
    </section>


    <!-- ══ SECTION 3 — CTA ══ -->
    <section id="section-cta">

        <div class="cta-layout">

            <div class="cta-left">
                <p class="cta-pre">Begin your journey with Old Union</p>
                <h2 class="cta-headline">
                    Building new<br>
                    <em>capital markets.</em>
                </h2>
                <p class="cta-statement">
                    Old Union operates at the intersection of financial infrastructure and community development — 
                    providing a regulated, transparent pathway for South Africans to invest in the businesses shaping their neighbourhoods.
                </p>

                <div class="provinces-strip" style="margin-top:2rem;">
                    <div class="provinces-label">Active across all provinces</div>
                    <div class="provinces-list">
                        <span class="province-pill">Gauteng</span>
                        <span class="province-pill">Western Cape</span>
                        <span class="province-pill">KwaZulu-Natal</span>
                        <span class="province-pill">Eastern Cape</span>
                        <span class="province-pill">Limpopo</span>
                        <span class="province-pill">Mpumalanga</span>
                        <span class="province-pill">Free State</span>
                        <span class="province-pill">North West</span>
                        <span class="province-pill">Northern Cape</span>
                    </div>
                </div>

                <p class="cta-legal">
                        Old Union operates through structured cooperative frameworks in compliance with South African law. 
                        Each opportunity is independently constituted, with clearly defined terms, governance, and contributor limits. 
                        This platform does not constitute financial advice. Contributors are encouraged to review all documentation before participating.
                </p>
            </div>

            <div class="cta-right">
                <button class="cta-btn cta-btn--gold" id="connectBtn">
                    <span>Invest Now</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
                <button class="cta-btn cta-btn--outline" id="portfolioBtn">
                    <span>Explore Opportunities</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
                <button class="cta-btn cta-btn--outline" id="scheduleBtn">
                    <span>Raise Capital</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>

        </div>
        <div class="cta-inner-rule"></div>
    </section>

</div><!-- /.snap-container -->

<!-- Navigation Dots -->
<div class="nav-dots" id="navDots">
    <div class="nav-dot active" data-section="section-1"></div>
    <div class="nav-dot" data-section="section-process"></div>    
    <div class="nav-dot" data-section="section-numbers"></div>
    <div class="nav-dot" data-section="section-instruments"></div>
    <div class="nav-dot" data-section="section-cta"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // ── Snap & nav dots ──
    const snapContainer = document.getElementById('snapContainer');
    const navDots = document.getElementById('navDots');
    const dots = navDots.querySelectorAll('.nav-dot');
    const sections = document.querySelectorAll('section');

    function updateActiveDot() {
        const mid = snapContainer.scrollTop + snapContainer.clientHeight / 2;
        let current = sections[0];
        sections.forEach(s => {
            if (mid >= s.offsetTop && mid < s.offsetTop + s.offsetHeight) current = s;
        });
        dots.forEach(d => {
            d.classList.toggle('active', d.dataset.section === current.id);
        });
    }

    dots.forEach(dot => {
        dot.addEventListener('click', function() {
            const target = document.getElementById(this.dataset.section);
            if (target) snapContainer.scrollTo({ top: target.offsetTop, behavior: 'smooth' });
        });
    });

    snapContainer.addEventListener('scroll', updateActiveDot);
    updateActiveDot();

    window.addEventListener('resize', function() {
        if (window.innerWidth <= 1024) {
            snapContainer.style.scrollSnapType = 'none';
            sections.forEach(s => s.style.scrollSnapAlign = 'none');
        } else {
            snapContainer.style.scrollSnapType = 'y mandatory';
            sections.forEach(s => s.style.scrollSnapAlign = 'start');
        }
    });

    // ── Stat counters (animate numbers on scroll into view) ──
    const statNums = document.querySelectorAll('.stat-number');
    const observed = new Set();

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !observed.has(entry.target)) {
                observed.add(entry.target);
                animateStat(entry.target);
            }
        });
    }, { threshold: 0.3 });

    statNums.forEach(n => observer.observe(n));

    function animateStat(el) {
        const raw = el.textContent;
        const match = raw.match(/([\d,]+)/);
        if (!match) return;
        const target = parseInt(match[1].replace(/,/g, ''));
        const sup = el.querySelector('sup') ? el.querySelector('sup').outerHTML : '';
        const prefix = raw.match(/^[R\s]+/) ? raw.match(/^[R\s]+/)[0] : '';
        const suffix = raw.replace(prefix, '').replace(/[\d,\s]+/, '').replace(/<sup>.*<\/sup>/, '');
        let start = 0;
        const dur = 1400;
        const startTime = performance.now();
        function tick(now) {
            const progress = Math.min((now - startTime) / dur, 1);
            const ease = 1 - Math.pow(1 - progress, 3);
            const val = Math.floor(ease * target);
            el.innerHTML = prefix + val.toLocaleString() + suffix + sup;
            if (progress < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }

    // ── CTA buttons ──
    document.getElementById('cta-Btn')?.addEventListener('click', () => window.open('/app/auth/register.php', '_blank'));
    document.getElementById('cta-Btn2')?.addEventListener('click', () => window.open('/app/company/create.php', '_blank'));
    document.getElementById('connectBtn')?.addEventListener('click', () => window.open('/app/auth/register.php', '_blank'));
    document.getElementById('learnBtn')?.addEventListener('click', () => window.location.href = '/app/discover/');
    document.getElementById('portfolioBtn')?.addEventListener('click', () => window.location.href = '/app/discover/');
    document.getElementById('scheduleBtn')?.addEventListener('click', () => window.open('/app/company/create.php', '_blank'));

});
</script>

</body>
</html>
