<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>How It Works | Old Union</title>
    <meta name="description" content="Old Union connects contributors with verified township and community businesses through structured, legally compliant investment instruments. Learn how the platform works.">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">

<style>
/* ══════════════════════════════════════════════
   1. TOKENS (identical to homepage)
══════════════════════════════════════════════ */
:root {
    --ink:          #07111f;
    --navy:         #0c1e33;
    --navy-deep:    #060f1a;
    --navy-mid:     #122844;
    --cream:        #f2ead8;
    --cream-light:  #f9f5ec;
    --cream-mid:    #ede5cf;
    --gold:         #a68a4a;
    --gold-light:   #c8a96a;
    --gold-pale:    #e8d9b5;
    --gold-dim:     rgba(166,138,74,.12);
    --gold-rule:    rgba(166,138,74,.35);
    --white:        #ffffff;
    --charcoal:     #1c2635;
    --slate:        #6b7a8d;
    --slate-light:  #8d9aaa;
    --rule:         rgba(255,255,255,.07);
    --rule-dark:    rgba(7,17,31,.1);
    --serif:        'Cormorant Garamond', Georgia, serif;
    --sans:         'DM Sans', system-ui, sans-serif;
    --mono:         'DM Mono', 'Courier New', monospace;
    --ease:         cubic-bezier(.4,0,.2,1);
    --transition:   0.35s cubic-bezier(.4,0,.2,1);
}

/* ══════════════════════════════════════════════
   2. RESET & BASE
══════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: var(--sans);
    line-height: 1.6;
    color: var(--white);
    overflow: hidden;
    background: var(--navy-deep);
}

/* ══════════════════════════════════════════════
   3. SNAP CONTAINER
══════════════════════════════════════════════ */
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

section::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0;
    width: 100%; height: 2px;
    background: linear-gradient(90deg, transparent 0%, var(--gold) 30%, var(--gold-light) 50%, var(--gold) 70%, transparent 100%);
    z-index: 10;
    opacity: 0.5;
}

/* ══════════════════════════════════════════════
   4. NAV BAR
══════════════════════════════════════════════ */
.site-nav {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 200;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 7vw;
    background: linear-gradient(to bottom, rgba(6,15,26,.95) 0%, transparent 100%);
    pointer-events: none;
}
.site-nav > * { pointer-events: all; }

.nav-wordmark {
    font-family: var(--serif);
    font-size: 1.15rem;
    font-weight: 300;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--white);
    text-decoration: none;
}
.nav-wordmark em {
    font-style: normal;
    color: var(--gold-light);
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 2rem;
    list-style: none;
}
.nav-links a {
    font-family: var(--mono);
    font-size: .65rem;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: rgba(255,255,255,.45);
    text-decoration: none;
    transition: color var(--transition);
}
.nav-links a:hover,
.nav-links a.active { color: var(--gold-light); }

.nav-cta {
    font-family: var(--mono);
    font-size: .65rem;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: var(--navy-deep);
    background: var(--gold);
    padding: .55rem 1.25rem;
    text-decoration: none;
    transition: background var(--transition);
}
.nav-cta:hover { background: var(--gold-light); }

/* ══════════════════════════════════════════════
   5. SHARED COMPONENTS
══════════════════════════════════════════════ */
.eyebrow {
    font-family: var(--mono);
    font-size: .65rem;
    font-weight: 400;
    letter-spacing: .3em;
    text-transform: uppercase;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: .75rem;
}
.eyebrow::before {
    content: '';
    display: block;
    width: 28px; height: 1px;
    background: var(--gold);
    flex-shrink: 0;
}
.eyebrow--light { color: var(--gold); }
.eyebrow--dark  { color: rgba(7,17,31,.4); }
.eyebrow--dark::before { background: rgba(7,17,31,.3); }

.display-heading {
    font-family: var(--serif);
    font-weight: 300;
    line-height: 1.05;
    letter-spacing: -.025em;
}
.display-heading em {
    font-style: italic;
    color: var(--gold-light);
}
.display-heading--dark { color: var(--ink); }
.display-heading--dark em { color: var(--gold); }

.gold-line {
    height: 1px;
    background: linear-gradient(90deg, var(--gold-rule) 0%, rgba(166,138,74,.04) 100%);
}
.gold-line--full { background: var(--gold-rule); }

/* ══════════════════════════════════════════════
   6. SECTION 0 — HERO
══════════════════════════════════════════════ */
#section-hero {
    background: var(--navy-deep);
    padding: 0 7vw;
    align-items: center;
}

/* Decorative grid lines */
#section-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(166,138,74,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(166,138,74,.04) 1px, transparent 1px);
    background-size: 80px 80px;
    pointer-events: none;
}

.hero-layout {
    max-width: 1300px;
    margin: 0 auto;
    width: 100%;
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 6vw;
    align-items: center;
}

.hero-overline {
    font-family: var(--mono);
    font-size: .65rem;
    letter-spacing: .35em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: .75rem;
}
.hero-overline::before {
    content: '';
    width: 28px; height: 1px;
    background: var(--gold);
}

.hero-main-heading {
    font-family: var(--serif);
    font-size: clamp(3.5rem, 7vw, 6.5rem);
    font-weight: 300;
    line-height: 1;
    letter-spacing: -.03em;
    color: var(--white);
    margin-bottom: 2rem;
}
.hero-main-heading em {
    font-style: italic;
    color: var(--gold-light);
    display: block;
}

.hero-intro {
    font-size: .92rem;
    font-weight: 300;
    line-height: 1.8;
    color: rgba(255,255,255,.5);
    max-width: 560px;
    margin-bottom: 2.5rem;
}

.hero-scroll-cue {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .25em;
    text-transform: uppercase;
    color: rgba(255,255,255,.2);
    display: flex;
    align-items: center;
    gap: .6rem;
}
.hero-scroll-cue::after {
    content: '';
    width: 40px; height: 1px;
    background: rgba(255,255,255,.15);
}

/* Right side — platform summary card */
.hero-card {
    border: 1px solid var(--gold-rule);
    background: rgba(166,138,74,.04);
    padding: 2rem;
}

.hero-card-title {
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .25em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--gold-rule);
}

.hero-card-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: .9rem 0;
    border-bottom: 1px solid var(--rule);
}
.hero-card-item:last-child { border-bottom: none; }

.hero-card-num {
    font-family: var(--serif);
    font-size: 2rem;
    font-weight: 300;
    color: rgba(255,255,255,.15);
    line-height: 1;
    min-width: 2.5rem;
    flex-shrink: 0;
}

.hero-card-text {}
.hero-card-label {
    font-family: var(--sans);
    font-size: .8rem;
    font-weight: 500;
    color: var(--white);
    margin-bottom: .15rem;
}
.hero-card-sub {
    font-size: .73rem;
    font-weight: 300;
    color: rgba(255,255,255,.38);
    line-height: 1.5;
}

/* ══════════════════════════════════════════════
   7. SECTION 1 — TWO PATHS (Split)
══════════════════════════════════════════════ */
#section-paths {
    padding: 0;
    align-items: stretch;
}

.paths-layout {
    width: 100%;
    height: 100%;
    display: grid;
    grid-template-columns: 1fr 1px 1fr;
}

.path-divider {
    background: linear-gradient(to bottom, transparent 5%, var(--gold-rule) 20%, var(--gold-rule) 80%, transparent 95%);
    position: relative;
    z-index: 2;
}
.path-divider::after {
    content: 'OR';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .3em;
    color: var(--gold);
    background: var(--navy-deep);
    padding: .5rem .2rem;
    white-space: nowrap;
    writing-mode: vertical-rl;
    text-orientation: mixed;
}

.path-panel {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 0 7vw;
    position: relative;
    cursor: pointer;
    transition: background var(--transition);
    text-decoration: none;
    color: inherit;
}

.path-panel--investor {
    background: var(--navy-deep);
    align-items: flex-end;
    text-align: right;
    padding-right: 5vw;
}
.path-panel--investor:hover { background: rgba(12,30,51,1); }

.path-panel--business {
    background: var(--charcoal);
    align-items: flex-start;
    text-align: left;
    padding-left: 5vw;
}
.path-panel--business:hover { background: rgba(30,40,55,1); }

/* Ghost large letter behind each panel */
.path-ghost {
    position: absolute;
    font-family: var(--serif);
    font-weight: 300;
    font-size: clamp(12rem, 20vw, 22rem);
    line-height: 1;
    letter-spacing: -.05em;
    color: rgba(255,255,255,.025);
    pointer-events: none;
    user-select: none;
    top: 50%;
    transform: translateY(-50%);
}
.path-panel--investor .path-ghost { right: -2rem; }
.path-panel--business .path-ghost { left: -2rem; }

.path-role-tag {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .3em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: .6rem;
}
.path-panel--investor .path-role-tag { flex-direction: row-reverse; }
.path-role-tag::before {
    content: '';
    width: 24px; height: 1px;
    background: var(--gold);
}

.path-heading {
    font-family: var(--serif);
    font-size: clamp(2.2rem, 4vw, 3.8rem);
    font-weight: 300;
    line-height: 1.08;
    letter-spacing: -.02em;
    color: var(--white);
    margin-bottom: 1rem;
}
.path-heading em {
    font-style: italic;
    color: var(--gold-light);
    display: block;
}

.path-body {
    font-size: .85rem;
    font-weight: 300;
    color: rgba(255,255,255,.45);
    line-height: 1.75;
    max-width: 360px;
    margin-bottom: 2rem;
}

.path-link {
    display: inline-flex;
    align-items: center;
    gap: .6rem;
    font-family: var(--mono);
    font-size: .65rem;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--gold-light);
    transition: gap var(--transition);
}
.path-link i { font-size: .6rem; transition: transform var(--transition); }
.path-panel:hover .path-link { gap: 1rem; }
.path-panel:hover .path-link i { transform: translateX(4px); }

.path-features {
    display: flex;
    flex-direction: column;
    gap: .5rem;
    margin-bottom: 2rem;
}
.path-feature {
    font-family: var(--mono);
    font-size: .65rem;
    letter-spacing: .08em;
    color: rgba(255,255,255,.3);
    display: flex;
    align-items: center;
    gap: .5rem;
}
.path-feature::before {
    content: '';
    width: 5px; height: 5px;
    border: 1px solid var(--gold-rule);
    flex-shrink: 0;
    transform: rotate(45deg);
}
.path-panel--investor .path-feature { flex-direction: row-reverse; }

/* ══════════════════════════════════════════════
   8. SECTION 2 — INVESTOR JOURNEY
══════════════════════════════════════════════ */
#section-investor {
    background: var(--navy-deep);
    padding: 0 7vw;
}

.journey-layout {
    max-width: 1300px;
    margin: 0 auto;
    width: 100%;
}

.journey-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 3rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid var(--rule);
    gap: 2rem;
    flex-wrap: wrap;
}

.journey-heading {
    font-family: var(--serif);
    font-size: clamp(2.4rem, 4.5vw, 4rem);
    font-weight: 300;
    line-height: 1.05;
    letter-spacing: -.025em;
    color: var(--white);
}
.journey-heading em { font-style: italic; color: var(--gold-light); }

.journey-note {
    max-width: 300px;
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .12em;
    color: rgba(255,255,255,.25);
    line-height: 1.7;
    text-align: right;
}

/* Timeline with accordion steps */
.journey-steps {
    display: flex;
    flex-direction: column;
    position: relative;
}

.journey-step {
    display: grid;
    grid-template-columns: 60px 1fr auto;
    gap: 0 2rem;
    padding: 1.1rem 0;
    border-bottom: 1px solid var(--rule);
    align-items: start;
    cursor: pointer;
    position: relative;
    transition: background var(--transition);
    padding-left: .5rem;
    padding-right: .5rem;
}
.journey-step:first-child { border-top: 1px solid var(--rule); }
.journey-step::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 2px;
    background: var(--gold);
    transform: scaleY(0);
    transform-origin: top;
    transition: transform .4s var(--ease);
}
.journey-step.open::before { transform: scaleY(1); }
.journey-step.open { background: rgba(166,138,74,.03); }

.journey-step-num {
    font-family: var(--serif);
    font-size: 2.2rem;
    font-weight: 300;
    line-height: 1;
    color: rgba(255,255,255,.12);
    padding-top: .25rem;
    transition: color var(--transition);
}
.journey-step.open .journey-step-num { color: var(--gold-light); }

.journey-step-main {}
.journey-step-title {
    font-family: var(--serif);
    font-size: 1.35rem;
    font-weight: 400;
    color: var(--white);
    line-height: 1.2;
    margin-bottom: .25rem;
    transition: color var(--transition);
    padding-top: .35rem;
}
.journey-step.open .journey-step-title { color: var(--gold-light); }

.journey-step-preview {
    font-size: .78rem;
    font-weight: 300;
    color: rgba(255,255,255,.35);
    font-family: var(--mono);
    letter-spacing: .08em;
}

.journey-step-body {
    grid-column: 2 / 3;
    overflow: hidden;
    max-height: 0;
    transition: max-height .4s var(--ease);
}
.journey-step.open .journey-step-body { max-height: 200px; }

.journey-step-content {
    padding: .85rem 0 .5rem;
    font-size: .84rem;
    font-weight: 300;
    color: rgba(255,255,255,.5);
    line-height: 1.75;
}

.journey-step-tags {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-top: .65rem;
    padding-bottom: .25rem;
}
.journey-step-tag {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .1em;
    color: var(--gold);
    padding: .2rem .55rem;
    border: 1px solid var(--gold-rule);
}

.journey-step-toggle {
    padding-top: .35rem;
    color: rgba(255,255,255,.25);
    font-size: .8rem;
    transition: all var(--transition);
    flex-shrink: 0;
}
.journey-step.open .journey-step-toggle {
    color: var(--gold);
    transform: rotate(180deg);
}

/* Wallet callout inside investor section */
.wallet-callout {
    margin-top: 1.75rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 1.25rem 1.5rem;
    border: 1px solid var(--gold-rule);
    background: var(--gold-dim);
}
.wallet-callout-icon {
    font-size: 1.4rem;
    color: var(--gold-light);
    flex-shrink: 0;
}
.wallet-callout-text {}
.wallet-callout-label {
    font-family: var(--sans);
    font-size: .82rem;
    font-weight: 500;
    color: var(--white);
    margin-bottom: .15rem;
}
.wallet-callout-sub {
    font-size: .75rem;
    font-weight: 300;
    color: rgba(255,255,255,.4);
}

/* ══════════════════════════════════════════════
   9. SECTION 3 — BUSINESS JOURNEY
══════════════════════════════════════════════ */
#section-business {
    background: var(--cream-light);
    padding: 0 7vw;
}

.biz-layout {
    max-width: 1300px;
    margin: 0 auto;
    width: 100%;
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 6vw;
    align-items: start;
}

.biz-sidebar {
    position: relative;
    padding-right: 4vw;
    border-right: 1px solid var(--rule-dark);
}

.biz-sidebar-heading {
    font-family: var(--serif);
    font-size: clamp(2.4rem, 4vw, 3.6rem);
    font-weight: 300;
    line-height: 1.05;
    letter-spacing: -.025em;
    color: var(--ink);
    margin-bottom: 1.5rem;
}
.biz-sidebar-heading em { font-style: italic; color: var(--gold); }

.biz-sidebar-body {
    font-size: .85rem;
    font-weight: 300;
    color: var(--slate);
    line-height: 1.8;
    margin-bottom: 2rem;
}

/* Verification checklist */
.verify-list {
    display: flex;
    flex-direction: column;
    gap: .65rem;
    margin-bottom: 2rem;
}
.verify-item {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
}
.verify-check {
    width: 18px; height: 18px;
    border: 1px solid rgba(166,138,74,.4);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: .1rem;
}
.verify-check i {
    font-size: .55rem;
    color: var(--gold);
}
.verify-text {
    font-size: .8rem;
    color: var(--slate);
    font-weight: 300;
    line-height: 1.4;
}
.verify-text strong {
    font-weight: 500;
    color: var(--ink);
    display: block;
    margin-bottom: .1rem;
}

.biz-apply-btn {
    display: inline-flex;
    align-items: center;
    gap: .75rem;
    font-family: var(--mono);
    font-size: .65rem;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--navy-deep);
    background: var(--gold);
    padding: .85rem 1.5rem;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all var(--transition);
}
.biz-apply-btn:hover { background: var(--gold-light); }
.biz-apply-btn i { font-size: .6rem; }

/* Business steps on the right */
.biz-steps {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.biz-step {
    display: flex;
    align-items: flex-start;
    gap: 1.75rem;
    padding: 1.5rem 0;
    border-bottom: 1px solid var(--rule-dark);
    position: relative;
}
.biz-step:first-child { border-top: 1px solid var(--rule-dark); }

/* Connector line between steps */
.biz-step-num-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex-shrink: 0;
    width: 48px;
}
.biz-step-num {
    width: 48px; height: 48px;
    border: 1px solid rgba(7,17,31,.12);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--mono);
    font-size: .72rem;
    font-weight: 400;
    letter-spacing: .1em;
    color: var(--gold);
    background: var(--cream-light);
    flex-shrink: 0;
    transition: all var(--transition);
}
.biz-step:hover .biz-step-num {
    background: var(--gold);
    color: var(--white);
    border-color: var(--gold);
}

.biz-step-connector {
    width: 1px;
    flex: 1;
    background: rgba(7,17,31,.08);
    min-height: 1.5rem;
    margin-top: .5rem;
}
.biz-step:last-child .biz-step-connector { display: none; }

.biz-step-content {}
.biz-step-label {
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: .4rem;
}
.biz-step-title {
    font-family: var(--serif);
    font-size: 1.3rem;
    font-weight: 400;
    color: var(--ink);
    line-height: 1.2;
    margin-bottom: .5rem;
}
.biz-step-desc {
    font-size: .82rem;
    font-weight: 300;
    color: var(--slate);
    line-height: 1.7;
}

.biz-step-meta {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-top: .65rem;
}
.biz-step-pill {
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .08em;
    color: rgba(7,17,31,.4);
    padding: .15rem .5rem;
    border: 1px solid rgba(7,17,31,.1);
}

/* ══════════════════════════════════════════════
   10. SECTION 4 — INSTRUMENTS TABLE
══════════════════════════════════════════════ */
#section-instruments {
    background: var(--charcoal);
    padding: 0 7vw;
}

.instr-layout {
    max-width: 1300px;
    margin: 0 auto;
    width: 100%;
}

.instr-header {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4vw;
    margin-bottom: 2.5rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid var(--rule);
    align-items: end;
}

.instr-heading {
    font-family: var(--serif);
    font-size: clamp(2.4rem, 4vw, 3.8rem);
    font-weight: 300;
    line-height: 1.05;
    letter-spacing: -.025em;
    color: var(--white);
}
.instr-heading em { font-style: italic; color: var(--gold-light); }

.instr-sub {
    font-size: .84rem;
    font-weight: 300;
    color: rgba(255,255,255,.38);
    line-height: 1.75;
    padding-top: .5rem;
    border-top: 1px solid var(--rule);
}

/* Comparison ledger table */
.ledger {
    width: 100%;
    border-collapse: collapse;
    font-size: .8rem;
}

.ledger th {
    padding: .85rem 1rem;
    text-align: left;
    font-family: var(--mono);
    font-size: .6rem;
    font-weight: 400;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: rgba(255,255,255,.35);
    border-bottom: 1px solid var(--rule);
}
.ledger th:first-child { width: 200px; color: rgba(255,255,255,.2); }

.ledger td {
    padding: .85rem 1rem;
    border-bottom: 1px solid var(--rule);
    color: rgba(255,255,255,.6);
    font-weight: 300;
    vertical-align: top;
    line-height: 1.5;
}
.ledger td:first-child {
    font-family: var(--mono);
    font-size: .65rem;
    letter-spacing: .1em;
    color: rgba(255,255,255,.25);
    text-transform: uppercase;
}
.ledger tr:last-child td { border-bottom: none; }
.ledger tr:hover td { background: rgba(255,255,255,.02); }

.ledger-col-head {
    display: flex;
    align-items: center;
    gap: .5rem;
}
.ledger-col-head i { color: var(--gold); font-size: .75rem; }

.ledger-tag {
    display: inline-block;
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .08em;
    padding: .15rem .45rem;
    border: 1px solid var(--gold-rule);
    color: var(--gold);
}
.ledger-tag--yes {
    border-color: rgba(34,197,94,.3);
    color: rgba(34,197,94,.8);
}
.ledger-tag--no {
    border-color: var(--rule);
    color: rgba(255,255,255,.2);
}

.ledger-value {
    font-family: var(--serif);
    font-size: 1.05rem;
    font-weight: 300;
    color: var(--white);
}

/* ══════════════════════════════════════════════
   11. SECTION 5 — COMPLIANCE
══════════════════════════════════════════════ */
#section-compliance {
    background: var(--cream-light);
    padding: 0 7vw;
    color: var(--ink);
}

.compliance-layout {
    max-width: 1300px;
    margin: 0 auto;
    width: 100%;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6vw;
    align-items: start;
}

.compliance-left {}

.compliance-heading {
    font-family: var(--serif);
    font-size: clamp(2.2rem, 4vw, 3.6rem);
    font-weight: 300;
    line-height: 1.05;
    letter-spacing: -.025em;
    color: var(--ink);
    margin-bottom: 1.5rem;
}
.compliance-heading em { font-style: italic; color: var(--gold); }

.compliance-intro {
    font-size: .88rem;
    font-weight: 300;
    color: var(--slate);
    line-height: 1.8;
    margin-bottom: 2rem;
}

/* Compliance pillars */
.compliance-pillars {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.compliance-pillar {
    display: flex;
    align-items: flex-start;
    gap: 1.25rem;
    padding: 1.25rem 0;
    border-bottom: 1px solid var(--rule-dark);
}
.compliance-pillar:first-child { border-top: 1px solid var(--rule-dark); }

.compliance-pillar-icon {
    width: 36px; height: 36px;
    border: 1px solid rgba(166,138,74,.25);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gold);
    font-size: .8rem;
    flex-shrink: 0;
}

.compliance-pillar-content {}
.compliance-pillar-title {
    font-size: .85rem;
    font-weight: 500;
    color: var(--ink);
    margin-bottom: .25rem;
}
.compliance-pillar-desc {
    font-size: .78rem;
    font-weight: 300;
    color: var(--slate);
    line-height: 1.65;
}

/* Right side — FAQ accordion */
.compliance-right {}

.faq-label {
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .25em;
    text-transform: uppercase;
    color: rgba(7,17,31,.35);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: .6rem;
}
.faq-label::before {
    content: '';
    width: 20px; height: 1px;
    background: rgba(7,17,31,.2);
}

.faq-item {
    border-bottom: 1px solid var(--rule-dark);
}
.faq-item:first-of-type { border-top: 1px solid var(--rule-dark); }

.faq-question {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 0;
    cursor: pointer;
    gap: 1rem;
    font-size: .85rem;
    font-weight: 500;
    color: var(--ink);
    list-style: none;
    transition: color var(--transition);
}
.faq-question:hover { color: var(--gold); }

.faq-question-text { flex: 1; }

.faq-toggle {
    width: 24px; height: 24px;
    border: 1px solid rgba(7,17,31,.12);
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(7,17,31,.3);
    font-size: .6rem;
    flex-shrink: 0;
    transition: all var(--transition);
}
.faq-item.open .faq-toggle {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--white);
    transform: rotate(180deg);
}

.faq-answer {
    overflow: hidden;
    max-height: 0;
    transition: max-height .4s var(--ease);
}
.faq-item.open .faq-answer { max-height: 300px; }

.faq-answer-inner {
    padding: 0 0 1.1rem;
    font-size: .8rem;
    font-weight: 300;
    color: var(--slate);
    line-height: 1.75;
}

/* ══════════════════════════════════════════════
   12. SECTION 6 — CTA FINAL
══════════════════════════════════════════════ */
#section-final {
    background: var(--ink);
    padding: 0 7vw;
    position: relative;
    overflow: hidden;
}
#section-final::after { content: none; }

/* Decorative concentric rings */
.final-rings {
    position: absolute;
    right: -15vw;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
}
.final-ring {
    position: absolute;
    border-radius: 50%;
    border: 1px solid rgba(166,138,74,.06);
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}

.final-layout {
    max-width: 1300px;
    margin: 0 auto;
    width: 100%;
    position: relative;
    z-index: 2;
}

.final-overline {
    font-family: var(--serif);
    font-size: clamp(1rem, 2vw, 1.25rem);
    font-weight: 300;
    font-style: italic;
    color: rgba(255,255,255,.3);
    margin-bottom: .75rem;
}

.final-heading {
    font-family: var(--serif);
    font-size: clamp(3rem, 7vw, 7rem);
    font-weight: 300;
    line-height: .95;
    letter-spacing: -.04em;
    color: var(--white);
    margin-bottom: 2.5rem;
}
.final-heading em {
    font-style: italic;
    color: var(--gold-light);
    display: block;
}

.final-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 5vw;
    align-items: start;
}

.final-left-body {
    font-size: .9rem;
    font-weight: 300;
    color: rgba(255,255,255,.45);
    line-height: 1.8;
    margin-bottom: 2.5rem;
}

.final-actions {
    display: flex;
    flex-direction: column;
    gap: .75rem;
}

.final-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 2rem;
    padding: 1.1rem 1.5rem;
    font-family: var(--sans);
    font-size: .82rem;
    font-weight: 500;
    letter-spacing: .05em;
    text-transform: uppercase;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all var(--transition);
}
.final-btn i { font-size: .7rem; opacity: .6; transition: all var(--transition); }
.final-btn:hover i { opacity: 1; transform: translateX(4px); }

.final-btn--gold { background: var(--gold); color: var(--navy-deep); }
.final-btn--gold:hover { background: var(--gold-light); }

.final-btn--outline {
    background: transparent;
    color: rgba(255,255,255,.55);
    border: 1px solid rgba(255,255,255,.12);
}
.final-btn--outline:hover {
    border-color: var(--gold-rule);
    color: var(--gold-light);
}

/* Right side — contact / more info block */
.final-right {}

.final-contact-block {
    border: 1px solid var(--rule);
    padding: 2rem;
    background: rgba(255,255,255,.02);
    margin-bottom: 1.5rem;
}
.final-contact-label {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .25em;
    text-transform: uppercase;
    color: rgba(255,255,255,.2);
    margin-bottom: 1.25rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--rule);
}
.final-contact-row {
    display: flex;
    align-items: center;
    gap: .85rem;
    padding: .65rem 0;
    border-bottom: 1px solid var(--rule);
}
.final-contact-row:last-child { border-bottom: none; }
.final-contact-icon { color: var(--gold); font-size: .8rem; width: 16px; }
.final-contact-text { font-size: .8rem; color: rgba(255,255,255,.4); font-weight: 300; }

.final-legal {
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .1em;
    color: rgba(255,255,255,.15);
    line-height: 1.7;
}

/* ══════════════════════════════════════════════
   13. NAV DOTS
══════════════════════════════════════════════ */
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
    width: 6px; height: 6px;
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
.nav-dot.active { background: var(--gold); transform: scale(1.2); }
.nav-dot.active::after { border-color: rgba(166,138,74,.4); }

/* Dot tooltips */
.nav-dot[data-label]:hover::before {
    content: attr(data-label);
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    font-family: var(--mono);
    font-size: .55rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--gold);
    background: var(--navy-deep);
    padding: .25rem .6rem;
    border: 1px solid var(--gold-rule);
    white-space: nowrap;
}

/* ══════════════════════════════════════════════
   14. ANIMATIONS
══════════════════════════════════════════════ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(2rem); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes gridPulse {
    0%, 100% { opacity: 0.5; }
    50%       { opacity: 1; }
}

.hero-layout { animation: fadeInUp .9s ease-out both; }

/* ══════════════════════════════════════════════
   15. RESPONSIVE
══════════════════════════════════════════════ */
@media (max-width: 1100px) {
    .hero-layout { grid-template-columns: 1fr; }
    .hero-card { display: none; }
    .biz-layout { grid-template-columns: 1fr; }
    .biz-sidebar { border-right: none; border-bottom: 1px solid var(--rule-dark); padding-right: 0; padding-bottom: 2rem; }
    .instr-header { grid-template-columns: 1fr; }
    .compliance-layout { grid-template-columns: 1fr; }
    .final-two-col { grid-template-columns: 1fr; }
    .final-right { display: none; }
    .nav-links { display: none; }
}

@media (max-width: 1024px) {
    .snap-container { height: auto; overflow-y: visible; scroll-snap-type: none; }
    section { height: auto; min-height: 100vh; scroll-snap-align: none; padding: 6rem 7vw 4rem; }
    #section-paths { padding: 0; min-height: 80vh; }
    .paths-layout { grid-template-columns: 1fr; }
    .path-divider { display: none; }
    .path-panel { padding: 3rem 7vw; min-height: 40vh; }
    .path-panel--investor { text-align: left; align-items: flex-start; }
    .path-panel--investor .path-role-tag { flex-direction: row; }
    .nav-dots { display: none; }
    body { overflow-y: scroll; }
}

@media (max-width: 768px) {
    .journey-step { grid-template-columns: 50px 1fr auto; }
    .final-heading { font-size: clamp(2.5rem, 8vw, 5rem); }
    .ledger th:nth-child(3), .ledger td:nth-child(3) { display: none; }
    section { padding: 5rem 5vw 3rem; }
}

@media (max-width: 600px) {
    .ledger th:nth-child(4), .ledger td:nth-child(4) { display: none; }
    .hero-main-heading { font-size: clamp(3rem, 12vw, 5rem); }
}
</style>
</head>
<?php include('public/header.php'); ?>
<body>

<!-- ════════ FIXED NAV ════════ -->

<!-- ════════ SNAP CONTAINER ════════ -->
<div class="snap-container" id="snapContainer">

<!-- ══ SECTION 0 — HERO ══ -->
<section id="section-hero">
    <div class="hero-layout">
        <div class="hero-left">
            <div class="hero-overline">How It Works</div>
            <h1 class="hero-main-heading">
                Capital, structured
                <em>for community.</em>
            </h1>
            <p class="hero-intro">
                Old Union is a regulated investment platform that connects contributors with verified township and community businesses across South Africa. The model is straightforward: businesses raise from contributors through structured instruments; contributors earn returns as the business grows.
            </p>
            <div class="hero-scroll-cue">Scroll to explore</div>
        </div>

        <aside class="hero-card">
            <div class="hero-card-title">Platform Overview — How Money Moves</div>

            <div class="hero-card-item">
                <div class="hero-card-num">01</div>
                <div class="hero-card-text">
                    <div class="hero-card-label">Businesses apply &amp; get verified</div>
                    <div class="hero-card-sub">KYC, CIPC checks, document review — full audit before listing</div>
                </div>
            </div>
            <div class="hero-card-item">
                <div class="hero-card-num">02</div>
                <div class="hero-card-text">
                    <div class="hero-card-label">Campaigns are reviewed &amp; opened</div>
                    <div class="hero-card-sub">Old Union approves deal terms, sets contributor cap, opens to the platform</div>
                </div>
            </div>
            <div class="hero-card-item">
                <div class="hero-card-num">03</div>
                <div class="hero-card-text">
                    <div class="hero-card-label">Contributors invest via wallet or EFT</div>
                    <div class="hero-card-sub">Funds held in escrow until minimum raise is confirmed</div>
                </div>
            </div>
            <div class="hero-card-item">
                <div class="hero-card-num">04</div>
                <div class="hero-card-text">
                    <div class="hero-card-label">Funds disbursed, agreements activate</div>
                    <div class="hero-card-sub">Capital released to business; return schedules begin</div>
                </div>
            </div>
            <div class="hero-card-item">
                <div class="hero-card-num">05</div>
                <div class="hero-card-text">
                    <div class="hero-card-label">Returns paid to contributor wallets</div>
                    <div class="hero-card-sub">Monthly distributions tracked and auditable on the platform</div>
                </div>
            </div>
        </aside>
    </div>
</section>

<!-- ══ SECTION 1 — TWO PATHS ══ -->
<section id="section-paths">
    <div class="paths-layout">

        <a class="path-panel path-panel--investor" href="#section-investor" id="investorPathBtn">
            <div class="path-ghost">I</div>
            <div class="path-role-tag">For Investors</div>
            <h2 class="path-heading">
                Invest in the<br>
                <em>community economy.</em>
            </h2>
            <div class="path-features">
                <div class="path-feature">Start from R 500</div>
                <div class="path-feature">Revenue-linked returns</div>
                <div class="path-feature">Full transparency &amp; audit trail</div>
                <div class="path-feature">Regulated &amp; legally structured</div>
            </div>
            <div class="path-link">
                Investor path <i class="fa-solid fa-arrow-right"></i>
            </div>
        </a>

        <div class="path-divider"></div>

        <a class="path-panel path-panel--business" href="#section-business" id="businessPathBtn">
            <div class="path-ghost">B</div>
            <div class="path-role-tag">For Businesses</div>
            <h2 class="path-heading">
                Raise capital from<br>
                <em>your community.</em>
            </h2>
            <div class="path-features">
                <div class="path-feature">List for free</div>
                <div class="path-feature">Raise up to R 10M</div>
                <div class="path-feature">Retain operational control</div>
                <div class="path-feature">Private placement compliant</div>
            </div>
            <div class="path-link">
                Business path <i class="fa-solid fa-arrow-right"></i>
            </div>
        </a>

    </div>
</section>

<!-- ══ SECTION 2 — INVESTOR JOURNEY ══ -->
<section id="section-investor">
    <div class="journey-layout">

        <div class="journey-header">
            <div>
                <div class="eyebrow eyebrow--light">Contributor Journey</div>
                <h2 class="journey-heading">
                    Investing<br><em>step by step.</em>
                </h2>
            </div>
            <div class="journey-note">
                From registration to your first return distribution. Each step is platform-guided and fully transparent.
            </div>
        </div>

        <div class="journey-steps" id="investorSteps">

            <div class="journey-step open" data-step="investor-1">
                <div class="journey-step-num">01</div>
                <div class="journey-step-main">
                    <div class="journey-step-title">Create Your Account</div>
                    <div class="journey-step-preview">Registration · Identity verification · Wallet setup</div>
                    <div class="journey-step-body">
                        <div class="journey-step-content">
                            Register with your email and create a secure password. Complete our identity verification process — this includes uploading a valid South African ID or passport. Once verified, your Old Union wallet is automatically created, ready to fund campaigns.
                        </div>
                        <div class="journey-step-tags">
                            <span class="journey-step-tag">Email + Password</span>
                            <span class="journey-step-tag">SA ID or Passport</span>
                            <span class="journey-step-tag">Takes under 5 minutes</span>
                        </div>
                    </div>
                </div>
                <div class="journey-step-toggle"><i class="fa-solid fa-chevron-down"></i></div>
            </div>

            <div class="journey-step" data-step="investor-2">
                <div class="journey-step-num">02</div>
                <div class="journey-step-main">
                    <div class="journey-step-title">Fund Your Wallet</div>
                    <div class="journey-step-preview">Instant card payment or bank EFT</div>
                    <div class="journey-step-body">
                        <div class="journey-step-content">
                            Add funds to your Old Union wallet using a debit or credit card (via YoCo, PCI DSS compliant) or by making a bank EFT to our escrow account. Wallet funds are always yours until you choose to invest.
                        </div>
                        <div class="journey-step-tags">
                            <span class="journey-step-tag">Card: instant</span>
                            <span class="journey-step-tag">EFT: 1–2 business days</span>
                            <span class="journey-step-tag">Withdrawable at any time</span>
                        </div>
                    </div>
                </div>
                <div class="journey-step-toggle"><i class="fa-solid fa-chevron-down"></i></div>
            </div>

            <div class="journey-step" data-step="investor-3">
                <div class="journey-step-num">03</div>
                <div class="journey-step-main">
                    <div class="journey-step-title">Discover &amp; Evaluate</div>
                    <div class="journey-step-preview">Browse campaigns · Review financials · Ask questions</div>
                    <div class="journey-step-body">
                        <div class="journey-step-content">
                            Browse verified businesses across all nine provinces. Filter by industry, area type (urban, township, rural), and campaign instrument. Review pitch decks, financial disclosures, milestones, and deal terms. Ask questions directly through the campaign Q&A — businesses are required to respond publicly.
                        </div>
                        <div class="journey-step-tags">
                            <span class="journey-step-tag">340+ verified businesses</span>
                            <span class="journey-step-tag">Filter by province &amp; industry</span>
                            <span class="journey-step-tag">Public Q&amp;A per campaign</span>
                        </div>
                    </div>
                </div>
                <div class="journey-step-toggle"><i class="fa-solid fa-chevron-down"></i></div>
            </div>

            <div class="journey-step" data-step="investor-4">
                <div class="journey-step-num">04</div>
                <div class="journey-step-main">
                    <div class="journey-step-title">Contribute &amp; Sign</div>
                    <div class="journey-step-preview">Select amount · Review agreement · Confirm</div>
                    <div class="journey-step-body">
                        <div class="journey-step-content">
                            Choose your contribution amount (above the campaign minimum), read the full investment agreement — including deal terms, your pro-rata share, and risk disclosures — then confirm. Payment is taken from your wallet or held as an EFT reservation.
                        </div>
                        <div class="journey-step-tags">
                            <span class="journey-step-tag">Min. contribution varies by campaign</span>
                            <span class="journey-step-tag">Digital agreement signing</span>
                            <span class="journey-step-tag">Max 50 contributors per campaign</span>
                        </div>
                    </div>
                </div>
                <div class="journey-step-toggle"><i class="fa-solid fa-chevron-down"></i></div>
            </div>

            <div class="journey-step" data-step="investor-5">
                <div class="journey-step-num">05</div>
                <div class="journey-step-main">
                    <div class="journey-step-title">Track &amp; Receive Returns</div>
                    <div class="journey-step-preview">Portfolio dashboard · Monthly updates · Wallet payouts</div>
                    <div class="journey-step-body">
                        <div class="journey-step-content">
                            Monitor your portfolio through your investor dashboard. Businesses post monthly financial updates, revenue reports, and milestone achievements. Returns are distributed to your Old Union wallet on schedule — viewable as individual payout ledger entries.
                        </div>
                        <div class="journey-step-tags">
                            <span class="journey-step-tag">Monthly financial disclosures</span>
                            <span class="journey-step-tag">Payout ledger per investment</span>
                            <span class="journey-step-tag">Full refund if minimum not met</span>
                        </div>
                    </div>
                </div>
                <div class="journey-step-toggle"><i class="fa-solid fa-chevron-down"></i></div>
            </div>

        </div>

        <div class="wallet-callout">
            <div class="wallet-callout-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div class="wallet-callout-text">
                <div class="wallet-callout-label">Escrow Protection on All Campaigns</div>
                <div class="wallet-callout-sub">If a campaign fails to reach its minimum raise, 100% of your contribution is refunded to your Old Union wallet — no fees, no delays.</div>
            </div>
        </div>

    </div>
</section>

<!-- ══ SECTION 3 — BUSINESS JOURNEY ══ -->
<section id="section-business">
    <div class="biz-layout">

        <div class="biz-sidebar">
            <div class="eyebrow eyebrow--dark">Business Journey</div>
            <h2 class="biz-sidebar-heading">
                Raise from<br>
                those who<br>
                <em>believe in you.</em>
            </h2>
            <p class="biz-sidebar-body">
                Old Union gives township and community businesses access to structured, patient capital — without surrendering equity or control. Our verification process is thorough by design: transparency builds trust, and trust builds funding.
            </p>
            <div class="verify-list">
                <div class="verify-item">
                    <div class="verify-check"><i class="fa-solid fa-check"></i></div>
                    <div class="verify-text">
                        <strong>CIPC Registration</strong>
                        Company registration certificate required
                    </div>
                </div>
                <div class="verify-item">
                    <div class="verify-check"><i class="fa-solid fa-check"></i></div>
                    <div class="verify-text">
                        <strong>Director ID &amp; Address</strong>
                        KYC verification of all primary directors
                    </div>
                </div>
                <div class="verify-item">
                    <div class="verify-check"><i class="fa-solid fa-check"></i></div>
                    <div class="verify-text">
                        <strong>Tax Clearance</strong>
                        SARS good standing recommended for verified badge
                    </div>
                </div>
                <div class="verify-item">
                    <div class="verify-check"><i class="fa-solid fa-check"></i></div>
                    <div class="verify-text">
                        <strong>Proof of Address</strong>
                        Business address verified, not older than 3 months
                    </div>
                </div>
            </div>
            <button class="biz-apply-btn" id="bizApplyBtn">
                List Your Business <i class="fa-solid fa-arrow-right"></i>
            </button>
        </div>

        <div class="biz-steps">

            <div class="biz-step">
                <div class="biz-step-num-wrap">
                    <div class="biz-step-num">01</div>
                    <div class="biz-step-connector"></div>
                </div>
                <div class="biz-step-content">
                    <div class="biz-step-label">Getting Started</div>
                    <div class="biz-step-title">Create a Company Profile</div>
                    <div class="biz-step-desc">Register your business on the platform using our guided onboarding wizard. Enter your company details, description, industry classification, and location. Upload your pitch deck and supporting financial documents.</div>
                    <div class="biz-step-meta">
                        <span class="biz-step-pill">Takes 20–30 minutes</span>
                        <span class="biz-step-pill">Free to list</span>
                        <span class="biz-step-pill">Save &amp; continue later</span>
                    </div>
                </div>
            </div>

            <div class="biz-step">
                <div class="biz-step-num-wrap">
                    <div class="biz-step-num">02</div>
                    <div class="biz-step-connector"></div>
                </div>
                <div class="biz-step-content">
                    <div class="biz-step-label">Compliance</div>
                    <div class="biz-step-title">Submit for Verification</div>
                    <div class="biz-step-desc">Upload your KYC documents — registration certificate, director IDs, proof of address, and tax clearance. The Old Union team conducts a full compliance review within 1–3 business days. You'll receive feedback or a verified badge.</div>
                    <div class="biz-step-meta">
                        <span class="biz-step-pill">1–3 business day review</span>
                        <span class="biz-step-pill">Email notification on outcome</span>
                    </div>
                </div>
            </div>

            <div class="biz-step">
                <div class="biz-step-num-wrap">
                    <div class="biz-step-num">03</div>
                    <div class="biz-step-connector"></div>
                </div>
                <div class="biz-step-content">
                    <div class="biz-step-label">Fundraising</div>
                    <div class="biz-step-title">Configure Your Campaign</div>
                    <div class="biz-step-desc">Choose your instrument type — Revenue Share, Cooperative Membership, or Fixed Return Loan. Set your raise target, minimum raise, contributor cap, and deal terms. Our campaign wizard walks you through every field with contextual guidance.</div>
                    <div class="biz-step-meta">
                        <span class="biz-step-pill">3 instrument types</span>
                        <span class="biz-step-pill">Max 50 contributors</span>
                        <span class="biz-step-pill">Old Union reviews before opening</span>
                    </div>
                </div>
            </div>

            <div class="biz-step">
                <div class="biz-step-num-wrap">
                    <div class="biz-step-num">04</div>
                    <div class="biz-step-connector"></div>
                </div>
                <div class="biz-step-content">
                    <div class="biz-step-label">Capital Raised</div>
                    <div class="biz-step-title">Receive Funds &amp; Launch</div>
                    <div class="biz-step-desc">Once your campaign closes and the minimum raise is confirmed, funds are released to your nominated business bank account. Your investment agreements are now active. Contributors begin tracking your progress through their dashboards.</div>
                    <div class="biz-step-meta">
                        <span class="biz-step-pill">2–4 business day disbursement</span>
                        <span class="biz-step-pill">Agreements auto-activate</span>
                    </div>
                </div>
            </div>

            <div class="biz-step">
                <div class="biz-step-num-wrap">
                    <div class="biz-step-num">05</div>
                    <div class="biz-step-connector"></div>
                </div>
                <div class="biz-step-content">
                    <div class="biz-step-label">Ongoing</div>
                    <div class="biz-step-title">Report, Distribute, Grow</div>
                    <div class="biz-step-desc">Post monthly financial updates and milestone reports through your company dashboard. Distribute returns to contributors on the agreed schedule — Old Union calculates each contributor's share and manages payout records. Build a track record for future raises.</div>
                    <div class="biz-step-meta">
                        <span class="biz-step-pill">Monthly reporting required</span>
                        <span class="biz-step-pill">Platform-managed distributions</span>
                        <span class="biz-step-pill">Multiple campaigns supported</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ══ SECTION 4 — INSTRUMENTS LEDGER ══ -->
<section id="section-instruments">
    <div class="instr-layout">

        <div class="instr-header">
            <div>
                <div class="eyebrow eyebrow--light">The Instruments</div>
                <h2 class="instr-heading">
                    Three instruments,<br><em>compared.</em>
                </h2>
            </div>
            <p class="instr-sub">
                All three instruments are legally structured under South African law, platform-administered, and limited to a maximum of 50 contributors per campaign under private placement regulations. Select the instrument that matches your risk appetite and investment horizon.
            </p>
        </div>

        <table class="ledger">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th><div class="ledger-col-head"><i class="fa-solid fa-chart-line"></i> Revenue Share</div></th>
                    <th><div class="ledger-col-head"><i class="fa-solid fa-people-roof"></i> Co-op Membership</div></th>
                    <th><div class="ledger-col-head"><i class="fa-solid fa-hand-holding-dollar"></i> Fixed Return Loan</div></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Return Type</td>
                    <td><span class="ledger-value">% of monthly revenue</span></td>
                    <td><span class="ledger-value">Cooperative surplus</span></td>
                    <td><span class="ledger-value">Fixed rate per annum</span></td>
                </tr>
                <tr>
                    <td>Return Timing</td>
                    <td>Monthly, as reported</td>
                    <td>Per surplus distribution cycle</td>
                    <td>Per agreed repayment schedule</td>
                </tr>
                <tr>
                    <td>Typical Term</td>
                    <td>12–60 months</td>
                    <td>Indefinite (membership)</td>
                    <td>6–36 months</td>
                </tr>
                <tr>
                    <td>Equity Transfer</td>
                    <td><span class="ledger-tag ledger-tag--no">No</span></td>
                    <td><span class="ledger-tag ledger-tag--no">No</span></td>
                    <td><span class="ledger-tag ledger-tag--no">No</span></td>
                </tr>
                <tr>
                    <td>Revenue-Linked</td>
                    <td><span class="ledger-tag ledger-tag--yes">Yes</span></td>
                    <td><span class="ledger-tag ledger-tag--yes">Yes</span></td>
                    <td><span class="ledger-tag ledger-tag--no">No</span></td>
                </tr>
                <tr>
                    <td>Predictability</td>
                    <td>Variable — moves with revenue</td>
                    <td>Variable — depends on surplus</td>
                    <td>Fixed — predictable schedule</td>
                </tr>
                <tr>
                    <td>Best Suited For</td>
                    <td>Revenue-generating SMEs &amp; retail</td>
                    <td>Township cooperatives &amp; worker-owned businesses</td>
                    <td>Established businesses with stable cash flow</td>
                </tr>
                <tr>
                    <td>Minimum Contribution</td>
                    <td>From R 500</td>
                    <td>From unit price</td>
                    <td>From R 500</td>
                </tr>
            </tbody>
        </table>

    </div>
</section>

<!-- ══ SECTION 5 — COMPLIANCE & FAQ ══ -->
<section id="section-compliance">
    <div class="compliance-layout">

        <div class="compliance-left">
            <div class="eyebrow eyebrow--dark">Legal Framework</div>
            <h2 class="compliance-heading">
                Built on<br>
                <em>solid ground.</em>
            </h2>
            <p class="compliance-intro">
                Old Union operates within a carefully defined legal framework under South African company and financial law. Every campaign is structured to comply with private placement exemptions. Our platform is not a financial services provider — it is a technology infrastructure that connects parties under clearly documented bilateral agreements.
            </p>

            <div class="compliance-pillars">
                <div class="compliance-pillar">
                    <div class="compliance-pillar-icon"><i class="fa-solid fa-scale-balanced"></i></div>
                    <div class="compliance-pillar-content">
                        <div class="compliance-pillar-title">Private Placement Exemption</div>
                        <div class="compliance-pillar-desc">All campaigns are limited to 50 investors, qualifying them under the Companies Act private placement exemption — no prospectus required, full legal compliance maintained.</div>
                    </div>
                </div>
                <div class="compliance-pillar">
                    <div class="compliance-pillar-icon"><i class="fa-solid fa-shield-check"></i></div>
                    <div class="compliance-pillar-content">
                        <div class="compliance-pillar-title">KYC &amp; FICA Compliance</div>
                        <div class="compliance-pillar-desc">All registered users and listed businesses undergo Know Your Customer verification. We retain documentation in line with FICA obligations.</div>
                    </div>
                </div>
                <div class="compliance-pillar">
                    <div class="compliance-pillar-icon"><i class="fa-solid fa-file-contract"></i></div>
                    <div class="compliance-pillar-content">
                        <div class="compliance-pillar-title">Legally Binding Agreements</div>
                        <div class="compliance-pillar-desc">Each investment generates a legally binding agreement between the contributor and the business, governed by South African law and enforceable in SA courts.</div>
                    </div>
                </div>
                <div class="compliance-pillar">
                    <div class="compliance-pillar-icon"><i class="fa-solid fa-vault"></i></div>
                    <div class="compliance-pillar-content">
                        <div class="compliance-pillar-title">Escrow &amp; Refund Protection</div>
                        <div class="compliance-pillar-desc">All campaign funds are held in escrow until the minimum raise is confirmed. Failed campaigns trigger automatic, full refunds to contributor wallets.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="compliance-right">
            <div class="faq-label">Frequently Asked Questions</div>

            <div class="faq-item open">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span class="faq-question-text">What happens if a campaign doesn't reach its minimum raise?</span>
                    <div class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        If a campaign closes without reaching its declared minimum raise, the campaign is automatically cancelled. Every contributor receives a full refund to their Old Union wallet — including those who paid via EFT. No fees are deducted on failed campaigns.
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span class="faq-question-text">Is Old Union a licensed financial services provider?</span>
                    <div class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Old Union is a technology platform — not an FSP. We provide the infrastructure for parties to enter into direct bilateral investment agreements. We do not give financial advice. All investment decisions remain the sole responsibility of the contributor. We strongly recommend seeking independent financial counsel before investing.
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span class="faq-question-text">How are returns calculated for revenue share campaigns?</span>
                    <div class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Each contributor's share is proportional to their contribution relative to the total amount raised. For example: if a campaign raises R 200 000 and you contributed R 10 000 (5%), and the revenue share is 8% per month, your monthly entitlement is 0.4% (5% × 8%) of the company's reported monthly revenue. The business posts revenue figures monthly; distributions are calculated and paid accordingly.
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span class="faq-question-text">Can I contribute to more than one campaign?</span>
                    <div class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Yes. There is no limit to the number of campaigns you can participate in across the platform. However, each campaign limits total contributors to a maximum of 50 — so popular campaigns can fill quickly. You may only contribute once per campaign.
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span class="faq-question-text">What does the verification process cost a business?</span>
                    <div class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Creating a company profile and submitting for verification is free. Old Union earns a platform fee on successfully closed campaigns only — a percentage of funds raised, taken at disbursement. There are no upfront fees, no listing fees, and no charge if a campaign fails to reach its minimum.
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span class="faq-question-text">What recourse do I have if a business stops reporting?</span>
                    <div class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Your investment agreement is a legally binding document under South African law. If a business fails to meet its obligations — including reporting and distributions — you have legal recourse through the agreement. Old Union will flag non-compliant businesses on the platform and escalate to its legal partners where necessary. We recommend all contributors read their agreements carefully before investing.
                    </div>
                </div>
            </div>

        </div>

    </div>
</section>

<!-- ══ SECTION 6 — FINAL CTA ══ -->
<section id="section-final">

    <div class="final-rings" aria-hidden="true">
        <div class="final-ring" style="width:200px;height:200px;"></div>
        <div class="final-ring" style="width:400px;height:400px;"></div>
        <div class="final-ring" style="width:600px;height:600px;"></div>
        <div class="final-ring" style="width:800px;height:800px;"></div>
        <div class="final-ring" style="width:1000px;height:1000px;"></div>
    </div>

    <div class="final-layout">
        <p class="final-overline">Ready to begin?</p>
        <h2 class="final-heading">
            Join Old Union.
            <em>Build together.</em>
        </h2>

        <div class="final-two-col">
            <div>
                <p class="final-left-body">
                    South Africa's community businesses have always been the backbone of the township economy. Old Union exists to connect that economic energy with capital — on terms that are fair, transparent, and legally sound. Whether you're starting with R 500 or raising R 5 million, the platform was built for you.
                </p>
                <div class="final-actions">
                    <button class="final-btn final-btn--gold" id="finalRegisterBtn">
                        <span>Register as Investor</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <button class="final-btn final-btn--outline" id="finalBrowseBtn">
                        <span>Browse Open Campaigns</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <button class="final-btn final-btn--outline" id="finalListBtn">
                        <span>List Your Business</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </div>
                <p class="final-legal" style="margin-top:2rem;">
                    This platform does not constitute financial advice. All investments carry risk including the possible loss of capital. Please read all campaign agreements carefully and consider seeking independent financial counsel. Old Union operates under the Companies Act private placement exemption. Max 50 contributors per campaign.
                </p>
            </div>

            <div class="final-right">
                <div class="final-contact-block">
                    <div class="final-contact-label">Get in Touch</div>
                    <div class="final-contact-row">
                        <i class="fa-solid fa-envelope final-contact-icon"></i>
                        <span class="final-contact-text">hello@oldunion.co.za</span>
                    </div>
                    <div class="final-contact-row">
                        <i class="fa-solid fa-phone final-contact-icon"></i>
                        <span class="final-contact-text">+27 86 012 3456</span>
                    </div>
                    <div class="final-contact-row">
                        <i class="fa-brands fa-linkedin final-contact-icon"></i>
                        <span class="final-contact-text">linkedin.com/company/oldunion</span>
                    </div>
                    <div class="final-contact-row">
                        <i class="fa-brands fa-x-twitter final-contact-icon"></i>
                        <span class="final-contact-text">@oldunionza</span>
                    </div>
                </div>
                <p class="final-legal">
                    Old Union Co-operative Management (Pty) Ltd<br>
                    Registered in South Africa · CIPC Compliant<br>
                    © 2025 Old Union. All rights reserved.
                </p>
            </div>
        </div>
    </div>

</section>

</div><!-- /.snap-container -->

<!-- ════════ NAV DOTS ════════ -->
<div class="nav-dots" id="navDots">
    <div class="nav-dot active" data-section="section-hero"        data-label="Overview"></div>
    <div class="nav-dot"        data-section="section-paths"       data-label="Two Paths"></div>
    <div class="nav-dot"        data-section="section-investor"    data-label="Investors"></div>
    <div class="nav-dot"        data-section="section-business"    data-label="Businesses"></div>
    <div class="nav-dot"        data-section="section-instruments" data-label="Instruments"></div>
    <div class="nav-dot"        data-section="section-compliance"  data-label="Compliance"></div>
    <div class="nav-dot"        data-section="section-final"       data-label="Get Started"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    /* ── Snap + nav dots ── */
    const snap    = document.getElementById('snapContainer');
    const dotsEl  = document.getElementById('navDots');
    const dots    = dotsEl.querySelectorAll('.nav-dot');
    const sections = document.querySelectorAll('section');

    function updateDots() {
        const mid = snap.scrollTop + snap.clientHeight / 2;
        let cur = sections[0];
        sections.forEach(s => {
            if (mid >= s.offsetTop && mid < s.offsetTop + s.offsetHeight) cur = s;
        });
        dots.forEach(d => d.classList.toggle('active', d.dataset.section === cur.id));
    }

    dots.forEach(dot => {
        dot.addEventListener('click', function() {
            const t = document.getElementById(this.dataset.section);
            if (t) snap.scrollTo({ top: t.offsetTop, behavior: 'smooth' });
        });
    });

    snap.addEventListener('scroll', updateDots);
    updateDots();

    window.addEventListener('resize', function() {
        const isMobile = window.innerWidth <= 1024;
        snap.style.scrollSnapType = isMobile ? 'none' : 'y mandatory';
        sections.forEach(s => s.style.scrollSnapAlign = isMobile ? 'none' : 'start');
    });

    /* ── Investor accordion ── */
    const investorSteps = document.querySelectorAll('#investorSteps .journey-step');
    investorSteps.forEach(step => {
        step.addEventListener('click', function() {
            const isOpen = this.classList.contains('open');
            investorSteps.forEach(s => s.classList.remove('open'));
            if (!isOpen) this.classList.add('open');
        });
    });

    /* ── FAQ accordion ── */
    window.toggleFaq = function(questionEl) {
        const item = questionEl.closest('.faq-item');
        const wasOpen = item.classList.contains('open');
        document.querySelectorAll('.faq-item').forEach(f => f.classList.remove('open'));
        if (!wasOpen) item.classList.add('open');
    };

    /* ── Path panel smooth scroll to section ── */
    document.getElementById('investorPathBtn').addEventListener('click', function(e) {
        e.preventDefault();
        const t = document.getElementById('section-investor');
        if (t) snap.scrollTo({ top: t.offsetTop, behavior: 'smooth' });
    });
    document.getElementById('businessPathBtn').addEventListener('click', function(e) {
        e.preventDefault();
        const t = document.getElementById('section-business');
        if (t) snap.scrollTo({ top: t.offsetTop, behavior: 'smooth' });
    });

    /* ── CTA buttons ── */
    document.getElementById('bizApplyBtn')?.addEventListener('click',      () => window.open('/app/company/create.php', '_blank'));
    document.getElementById('finalRegisterBtn')?.addEventListener('click', () => window.open('/app/auth/register.php', '_blank'));
    document.getElementById('finalBrowseBtn')?.addEventListener('click',   () => window.location.href = '/app/discover/');
    document.getElementById('finalListBtn')?.addEventListener('click',     () => window.open('/app/company/create.php', '_blank'));

    /* ── Animate nav on scroll (hide/show brand) ── */
    snap.addEventListener('scroll', function() {
        const nav = document.querySelector('.site-nav');
        nav.style.opacity = snap.scrollTop > 80 ? '0.85' : '1';
    });

    /* ── Entrance animation for step items ── */
    const stepObserver = new IntersectionObserver(entries => {
        entries.forEach((entry, i) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }, i * 80);
                stepObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    document.querySelectorAll('.biz-step, .journey-step, .compliance-pillar, .faq-item').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(16px)';
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        stepObserver.observe(el);
    });

});
</script>

</body>
</html>
