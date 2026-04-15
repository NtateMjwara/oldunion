<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Listing Centre | Old Union</title>
<meta name="description" content="List your township or community business on the Old Union platform. Raise structured capital from up to 50 contributors under South African private placement law.">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root {
    --ink:#07111f; --navy:#0c1e33; --navy-deep:#060f1a; --navy-mid:#122844;
    --cream:#f2ead8; --cream-light:#f9f5ec; --cream-mid:#ede5cf;
    --gold:#a68a4a; --gold-light:#c8a96a; --gold-pale:#e8d9b5;
    --gold-dim:rgba(166,138,74,.1); --gold-rule:rgba(166,138,74,.28);
    --white:#ffffff; --charcoal:#1c2635; --slate:#6b7a8d; --slate-light:#8d9aaa;
    --rule:rgba(255,255,255,.07); --rule-dark:rgba(7,17,31,.1); --rule-cream:rgba(7,17,31,.08);
    --serif:'Cormorant Garamond',Georgia,serif; --sans:'DM Sans',system-ui,sans-serif;
    --mono:'DM Mono','Courier New',monospace; --ease:cubic-bezier(.4,0,.2,1); --t:.35s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:var(--sans);background:var(--cream-light);color:var(--ink);line-height:1.6;overflow-x:hidden;}

/* ── NAV ── */
.site-nav{position:sticky;top:0;z-index:200;background:rgba(249,245,236,.97);backdrop-filter:blur(12px);border-bottom:1px solid var(--rule-dark);display:flex;align-items:center;justify-content:space-between;padding:1.1rem 7vw;}
.nav-wordmark{font-family:var(--serif);font-size:1.1rem;font-weight:300;letter-spacing:.12em;text-transform:uppercase;color:var(--ink);text-decoration:none;}
.nav-wordmark em{font-style:normal;color:var(--gold);}
.nav-links{display:flex;align-items:center;gap:2rem;list-style:none;}
.nav-links a{font-family:var(--mono);font-size:.62rem;letter-spacing:.2em;text-transform:uppercase;color:rgba(7,17,31,.4);text-decoration:none;transition:color var(--t);}
.nav-links a:hover,.nav-links a.active{color:var(--gold);}
.nav-cta{font-family:var(--mono);font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;color:var(--white);background:var(--navy-deep);padding:.55rem 1.25rem;text-decoration:none;transition:background var(--t);}
.nav-cta:hover{background:var(--navy);}

/* ── UTILS ── */
.container{max-width:1240px;margin:0 auto;padding:0 7vw;width:100%;}
.eyebrow{font-family:var(--mono);font-size:.62rem;letter-spacing:.3em;text-transform:uppercase;display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;}
.eyebrow::before{content:'';width:28px;height:1px;flex-shrink:0;}
.eyebrow--dark{color:rgba(7,17,31,.38);}
.eyebrow--dark::before{background:rgba(7,17,31,.25);}
.eyebrow--gold{color:var(--gold);}
.eyebrow--gold::before{background:var(--gold);}
.eyebrow--light{color:var(--gold);}
.eyebrow--light::before{background:var(--gold);}
.gold-rule-line{height:2px;background:linear-gradient(90deg,transparent 0%,var(--gold) 30%,var(--gold-light) 50%,var(--gold) 70%,transparent 100%);opacity:.45;}
.dark-rule-line{height:1px;background:var(--rule-dark);}

/* ── HERO — dark on cream ── */
.hero{background:var(--ink);padding:8rem 0 0;position:relative;overflow:hidden;color:var(--white);}
.hero::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(166,138,74,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(166,138,74,.03) 1px,transparent 1px);background-size:72px 72px;pointer-events:none;}
.hero-inner{display:grid;grid-template-columns:1fr 400px;gap:6vw;align-items:start;}
.hero-label{font-family:var(--mono);font-size:.62rem;letter-spacing:.35em;text-transform:uppercase;color:var(--gold);display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;}
.hero-label::before{content:'';width:28px;height:1px;background:var(--gold);}
.hero-heading{font-family:var(--serif);font-size:clamp(3rem,6.5vw,6rem);font-weight:300;line-height:.97;letter-spacing:-.03em;color:var(--white);margin-bottom:1.75rem;}
.hero-heading em{font-style:italic;color:var(--gold-light);display:block;}
.hero-body{font-size:.92rem;font-weight:300;line-height:1.82;color:rgba(255,255,255,.5);max-width:520px;margin-bottom:2.5rem;}
.hero-actions{display:flex;gap:.75rem;flex-wrap:wrap;}
.hero-btn{display:inline-flex;align-items:center;gap:.6rem;font-family:var(--mono);font-size:.65rem;letter-spacing:.18em;text-transform:uppercase;padding:.8rem 1.5rem;text-decoration:none;cursor:pointer;border:none;transition:all var(--t);}
.hero-btn--gold{background:var(--gold);color:var(--navy-deep);}
.hero-btn--gold:hover{background:var(--gold-light);}
.hero-btn--outline{background:transparent;color:rgba(255,255,255,.5);border:1px solid rgba(255,255,255,.15);}
.hero-btn--outline:hover{border-color:var(--gold-rule);color:var(--gold-light);}

/* Hero right: quick-facts panel */
.hero-panel{border:1px solid var(--gold-rule);background:rgba(166,138,74,.06);padding:1.75rem;align-self:start;}
.hero-panel-title{font-family:var(--mono);font-size:.58rem;letter-spacing:.25em;text-transform:uppercase;color:var(--gold);margin-bottom:1.25rem;padding-bottom:.85rem;border-bottom:1px solid var(--gold-rule);}
.hp-row{display:flex;align-items:flex-start;gap:.85rem;padding:.75rem 0;border-bottom:1px solid var(--rule);}
.hp-row:last-child{border-bottom:none;}
.hp-icon{color:var(--gold);font-size:.85rem;width:18px;flex-shrink:0;margin-top:.1rem;}
.hp-label{font-size:.8rem;font-weight:500;color:var(--white);margin-bottom:.1rem;}
.hp-sub{font-size:.73rem;font-weight:300;color:rgba(255,255,255,.38);line-height:1.4;}

/* Hero bottom strip — key numbers */
.hero-strip{background:rgba(255,255,255,.04);border-top:1px solid var(--rule);margin-top:4rem;padding:2rem 0;}
.hero-strip-inner{display:grid;grid-template-columns:repeat(4,1fr);gap:0;}
.hs-item{padding:0 2rem;border-right:1px solid var(--rule);text-align:center;}
.hs-item:last-child{border-right:none;}
.hs-num{font-family:var(--serif);font-size:2.5rem;font-weight:300;color:var(--white);line-height:1;letter-spacing:-.02em;}
.hs-num sup{font-size:.45em;color:var(--gold-light);vertical-align:super;}
.hs-label{font-family:var(--mono);font-size:.58rem;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-top:.35rem;line-height:1.4;}

/* ── WHY LIST section ── */
.why-section{padding:7rem 0;background:var(--cream-light);}
.why-grid{display:grid;grid-template-columns:1fr 1fr;gap:6vw;align-items:start;}
.why-heading{font-family:var(--serif);font-size:clamp(2.4rem,4.5vw,3.8rem);font-weight:300;line-height:1.05;letter-spacing:-.025em;color:var(--ink);margin-bottom:1.5rem;}
.why-heading em{font-style:italic;color:var(--gold);}
.why-body{font-size:.88rem;font-weight:300;color:var(--slate);line-height:1.82;margin-bottom:2rem;}
.why-quote{padding:1.5rem 1.75rem;border-left:2px solid var(--gold);background:rgba(166,138,74,.05);font-family:var(--serif);font-size:1.25rem;font-weight:300;font-style:italic;color:var(--ink);line-height:1.5;}
.why-quote cite{display:block;font-family:var(--mono);font-size:.6rem;font-style:normal;letter-spacing:.15em;text-transform:uppercase;color:rgba(7,17,31,.35);margin-top:.85rem;}

/* Why right: benefit cards */
.why-benefits{display:flex;flex-direction:column;gap:0;}
.benefit-row{display:flex;align-items:flex-start;gap:1rem;padding:1.25rem 0;border-bottom:1px solid var(--rule-dark);}
.benefit-row:first-child{border-top:1px solid var(--rule-dark);}
.benefit-icon{width:38px;height:38px;border:1px solid rgba(166,138,74,.22);display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:.8rem;flex-shrink:0;}
.benefit-title{font-size:.86rem;font-weight:500;color:var(--ink);margin-bottom:.18rem;}
.benefit-desc{font-size:.78rem;font-weight:300;color:var(--slate);line-height:1.65;}

/* ── ELIGIBILITY section ── */
.eligibility-section{padding:7rem 0;background:var(--navy-deep);color:var(--white);}
.elig-grid{display:grid;grid-template-columns:340px 1fr;gap:6vw;align-items:start;}
.elig-sidebar{}
.elig-heading{font-family:var(--serif);font-size:clamp(2.2rem,4vw,3.4rem);font-weight:300;line-height:1.05;letter-spacing:-.025em;color:var(--white);margin-bottom:1.25rem;}
.elig-heading em{font-style:italic;color:var(--gold-light);}
.elig-intro{font-size:.86rem;font-weight:300;color:rgba(255,255,255,.45);line-height:1.82;margin-bottom:1.75rem;}
.elig-note{font-family:var(--mono);font-size:.62rem;letter-spacing:.1em;color:rgba(255,255,255,.2);line-height:1.7;padding:.85rem 1rem;border:1px solid var(--rule);background:rgba(255,255,255,.02);}

/* Requirements table */
.req-table{width:100%;}
.req-category{margin-bottom:2.5rem;}
.req-category:last-child{margin-bottom:0;}
.req-cat-title{font-family:var(--mono);font-size:.6rem;letter-spacing:.25em;text-transform:uppercase;color:var(--gold);margin-bottom:1rem;padding-bottom:.65rem;border-bottom:1px solid var(--gold-rule);display:flex;align-items:center;gap:.6rem;}
.req-cat-title i{font-size:.7rem;}
.req-rows{display:flex;flex-direction:column;}
.req-row{display:grid;grid-template-columns:1fr auto auto;gap:1rem;align-items:center;padding:.9rem 0;border-bottom:1px solid var(--rule);}
.req-row:last-child{border-bottom:none;}
.req-name{font-size:.84rem;font-weight:400;color:var(--white);}
.req-desc{font-size:.74rem;font-weight:300;color:rgba(255,255,255,.38);margin-top:.1rem;}
.req-badge{font-family:var(--mono);font-size:.58rem;letter-spacing:.08em;padding:.2rem .5rem;white-space:nowrap;}
.req-badge--req{border:1px solid var(--gold-rule);color:var(--gold);}
.req-badge--rec{border:1px solid var(--rule);color:rgba(255,255,255,.3);}
.req-status{width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:.65rem;}
.req-status--req{color:var(--gold);}
.req-status--rec{color:rgba(255,255,255,.2);}

/* ── PROCESS STEPS section ── */
.process-section{padding:7rem 0;background:var(--cream-light);}
.process-header{display:grid;grid-template-columns:1fr 1fr;gap:5vw;align-items:end;margin-bottom:5rem;padding-bottom:3rem;border-bottom:1px solid var(--rule-dark);}
.process-heading{font-family:var(--serif);font-size:clamp(2.4rem,4.5vw,3.8rem);font-weight:300;line-height:1.05;letter-spacing:-.025em;color:var(--ink);}
.process-heading em{font-style:italic;color:var(--gold);}
.process-sub{font-size:.86rem;font-weight:300;color:var(--slate);line-height:1.8;padding-top:.75rem;border-top:1px solid var(--rule-dark);align-self:end;}

/* Large numbered steps */
.process-steps{display:flex;flex-direction:column;gap:0;}
.p-step{display:grid;grid-template-columns:120px 1fr 1fr;gap:0 4vw;align-items:start;padding:3rem 0;border-bottom:1px solid var(--rule-dark);position:relative;}
.p-step:first-child{border-top:1px solid var(--rule-dark);}
.p-step::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--gold),transparent);opacity:0;transition:opacity var(--t);}
.p-step:hover::before{opacity:1;}

.p-step-num{font-family:var(--serif);font-size:5rem;font-weight:300;line-height:1;letter-spacing:-.04em;color:rgba(7,17,31,.08);padding-top:.25rem;}
.p-step:hover .p-step-num{color:rgba(166,138,74,.2);}

.p-step-left{}
.p-step-label{font-family:var(--mono);font-size:.58rem;letter-spacing:.22em;text-transform:uppercase;color:var(--gold);margin-bottom:.6rem;}
.p-step-title{font-family:var(--serif);font-size:1.7rem;font-weight:400;color:var(--ink);line-height:1.2;letter-spacing:-.015em;margin-bottom:.75rem;}
.p-step-desc{font-size:.84rem;font-weight:300;color:var(--slate);line-height:1.78;}

.p-step-right{padding-top:.5rem;}
.p-step-detail-title{font-family:var(--mono);font-size:.58rem;letter-spacing:.2em;text-transform:uppercase;color:rgba(7,17,31,.28);margin-bottom:.85rem;}
.p-step-items{display:flex;flex-direction:column;gap:.5rem;}
.p-step-item{display:flex;align-items:flex-start;gap:.65rem;font-size:.78rem;font-weight:300;color:var(--slate);line-height:1.5;}
.p-step-item-dot{width:5px;height:5px;border:1px solid var(--gold);transform:rotate(45deg);flex-shrink:0;margin-top:.35rem;}
.p-step-item strong{font-weight:500;color:var(--ink);}
.p-step-tag-row{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:1rem;}
.p-step-tag{font-family:var(--mono);font-size:.58rem;letter-spacing:.08em;color:rgba(7,17,31,.35);padding:.18rem .5rem;border:1px solid var(--rule-dark);}
.p-step-tag--gold{color:var(--gold);border-color:var(--gold-rule);}

/* Timeline connector between big steps */
.p-step-connector{display:flex;align-items:center;justify-content:flex-start;padding-left:calc(120px + 4vw);gap:.6rem;padding-bottom:.65rem;color:rgba(7,17,31,.2);}
.p-step-connector i{font-size:.65rem;}
.p-step-connector span{font-family:var(--mono);font-size:.58rem;letter-spacing:.15em;text-transform:uppercase;}

/* ── CAMPAIGN CONFIG section ── */
.config-section{padding:7rem 0;background:var(--charcoal);color:var(--white);}
.config-header{display:grid;grid-template-columns:1fr 1fr;gap:5vw;align-items:end;margin-bottom:4rem;padding-bottom:3rem;border-bottom:1px solid var(--rule);}
.config-heading{font-family:var(--serif);font-size:clamp(2.2rem,4vw,3.6rem);font-weight:300;line-height:1.05;letter-spacing:-.025em;color:var(--white);}
.config-heading em{font-style:italic;color:var(--gold-light);}
.config-sub{font-size:.86rem;font-weight:300;color:rgba(255,255,255,.4);line-height:1.8;padding-top:.75rem;border-top:1px solid var(--rule);align-self:end;}

.config-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:0;border:1px solid var(--rule);}
.cfg-card{padding:2rem 1.75rem;border-right:1px solid var(--rule);}
.cfg-card:last-child{border-right:none;}
.cfg-card-icon{width:38px;height:38px;border:1px solid var(--gold-rule);display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:.85rem;margin-bottom:1.1rem;}
.cfg-card-name{font-family:var(--serif);font-size:1.35rem;font-weight:400;color:var(--white);margin-bottom:.5rem;}
.cfg-card-desc{font-size:.79rem;font-weight:300;color:rgba(255,255,255,.4);line-height:1.7;margin-bottom:1.25rem;}
.cfg-detail-label{font-family:var(--mono);font-size:.57rem;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.22);margin-bottom:.6rem;}
.cfg-fields{display:flex;flex-direction:column;gap:.4rem;}
.cfg-field{display:flex;align-items:center;justify-content:space-between;gap:.75rem;font-size:.76rem;color:rgba(255,255,255,.35);padding:.35rem 0;border-bottom:1px solid var(--rule);}
.cfg-field:last-child{border-bottom:none;}
.cfg-field span:last-child{font-family:var(--mono);font-size:.65rem;color:var(--gold);white-space:nowrap;}

/* Bottom: reviewer callout */
.review-callout{margin-top:3rem;padding:1.75rem 2rem;border:1px solid var(--gold-rule);background:rgba(166,138,74,.05);display:grid;grid-template-columns:auto 1fr;gap:1.5rem;align-items:center;}
.rc-icon{font-size:2rem;color:var(--gold);}
.rc-title{font-family:var(--serif);font-size:1.2rem;font-weight:400;color:var(--white);margin-bottom:.35rem;}
.rc-body{font-size:.8rem;font-weight:300;color:rgba(255,255,255,.42);line-height:1.7;}

/* ── ONGOING OBLIGATIONS section ── */
.ongoing-section{padding:7rem 0;background:var(--cream-light);}
.ongoing-header{display:grid;grid-template-columns:1fr 1fr;gap:5vw;align-items:end;margin-bottom:4rem;padding-bottom:3rem;border-bottom:1px solid var(--rule-dark);}
.ongoing-heading{font-family:var(--serif);font-size:clamp(2.2rem,4vw,3.4rem);font-weight:300;line-height:1.05;letter-spacing:-.025em;color:var(--ink);}
.ongoing-heading em{font-style:italic;color:var(--gold);}
.ongoing-sub{font-size:.86rem;font-weight:300;color:var(--slate);line-height:1.8;padding-top:.75rem;border-top:1px solid var(--rule-dark);align-self:end;}
.obligations-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border:1px solid var(--rule-dark);}
.ob-card{padding:2rem 1.75rem;border-right:1px solid var(--rule-dark);}
.ob-card:last-child{border-right:none;}
.ob-label{font-family:var(--mono);font-size:.58rem;letter-spacing:.2em;text-transform:uppercase;color:rgba(7,17,31,.28);margin-bottom:.75rem;}
.ob-title{font-family:var(--serif);font-size:1.25rem;font-weight:400;color:var(--ink);margin-bottom:.5rem;line-height:1.2;}
.ob-desc{font-size:.78rem;font-weight:300;color:var(--slate);line-height:1.7;}
.ob-items{display:flex;flex-direction:column;gap:.4rem;margin-top:1rem;}
.ob-item{font-family:var(--mono);font-size:.62rem;letter-spacing:.06em;color:rgba(7,17,31,.4);display:flex;align-items:center;gap:.5rem;}
.ob-item::before{content:'';width:4px;height:4px;background:var(--gold);border-radius:50%;flex-shrink:0;}

/* Consequence strip */
.consequence-strip{margin-top:3rem;display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;}
.consequence-card{padding:1.5rem;border:1px solid var(--rule-dark);}
.cc-title{font-size:.84rem;font-weight:500;color:var(--ink);margin-bottom:.4rem;display:flex;align-items:center;gap:.6rem;}
.cc-title i{color:var(--gold);font-size:.8rem;}
.cc-body{font-size:.78rem;font-weight:300;color:var(--slate);line-height:1.65;}

/* ── FAQ section ── */
.listing-faq{padding:6rem 0;background:var(--navy-deep);color:var(--white);}
.faq-2col{display:grid;grid-template-columns:360px 1fr;gap:6vw;align-items:start;}
.faq-2col-left{}
.faq-sidebar-heading{font-family:var(--serif);font-size:clamp(2rem,3.5vw,3rem);font-weight:300;line-height:1.1;letter-spacing:-.02em;color:var(--white);margin-bottom:1rem;}
.faq-sidebar-heading em{font-style:italic;color:var(--gold-light);}
.faq-sidebar-body{font-size:.84rem;font-weight:300;color:rgba(255,255,255,.4);line-height:1.8;margin-bottom:2rem;}
.faq-contact{display:flex;align-items:center;gap:.75rem;padding:.85rem 1.1rem;border:1px solid var(--gold-rule);background:rgba(166,138,74,.05);font-size:.78rem;color:rgba(255,255,255,.5);}
.faq-contact i{color:var(--gold);}
.faq-contact a{color:var(--gold-light);text-decoration:none;}
.faq-list{display:flex;flex-direction:column;}
.faq-item{border-bottom:1px solid var(--rule);}
.faq-item:first-child{border-top:1px solid var(--rule);}
.faq-q{display:flex;align-items:center;justify-content:space-between;padding:1rem 0;cursor:pointer;gap:1rem;font-size:.86rem;font-weight:500;color:var(--white);transition:color var(--t);}
.faq-q:hover{color:var(--gold-light);}
.faq-q-txt{flex:1;}
.faq-tog{width:24px;height:24px;border:1px solid var(--rule);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.22);font-size:.6rem;flex-shrink:0;transition:all var(--t);}
.faq-item.open .faq-tog{background:var(--gold);border-color:var(--gold);color:var(--white);transform:rotate(180deg);}
.faq-ans{overflow:hidden;max-height:0;transition:max-height .42s var(--ease);}
.faq-item.open .faq-ans{max-height:400px;}
.faq-ans-inner{padding:0 0 1rem;font-size:.8rem;font-weight:300;color:rgba(255,255,255,.45);line-height:1.78;}

/* ── APPLY CTA section ── */
.apply-section{background:var(--cream-light);padding:7rem 0 0;}
.apply-inner{background:var(--ink);padding:6rem 7vw;position:relative;overflow:hidden;}
.apply-rings{position:absolute;right:-5vw;top:50%;transform:translateY(-50%);pointer-events:none;}
.apply-ring{position:absolute;border-radius:50%;border:1px solid rgba(166,138,74,.05);top:50%;left:50%;transform:translate(-50%,-50%);}
.apply-content{position:relative;z-index:2;display:grid;grid-template-columns:1fr 360px;gap:6vw;align-items:center;}
.apply-pre{font-family:var(--serif);font-size:1.1rem;font-weight:300;font-style:italic;color:rgba(255,255,255,.3);margin-bottom:.5rem;}
.apply-heading{font-family:var(--serif);font-size:clamp(2.8rem,5.5vw,5rem);font-weight:300;line-height:.97;letter-spacing:-.035em;color:var(--white);margin-bottom:1.75rem;}
.apply-heading em{font-style:italic;color:var(--gold-light);display:block;}
.apply-body{font-size:.88rem;font-weight:300;color:rgba(255,255,255,.4);line-height:1.8;max-width:480px;margin-bottom:1.5rem;}
.apply-legal{font-family:var(--mono);font-size:.57rem;letter-spacing:.1em;color:rgba(255,255,255,.14);line-height:1.7;max-width:480px;}
.apply-actions{display:flex;flex-direction:column;gap:.65rem;}
.a-btn{display:flex;align-items:center;justify-content:space-between;gap:1.5rem;padding:1.1rem 1.4rem;font-family:var(--sans);font-size:.8rem;font-weight:500;letter-spacing:.05em;text-transform:uppercase;cursor:pointer;border:none;text-decoration:none;transition:all var(--t);}
.a-btn i{font-size:.7rem;opacity:.6;transition:all var(--t);}
.a-btn:hover i{opacity:1;transform:translateX(4px);}
.a-btn--gold{background:var(--gold);color:var(--navy-deep);}
.a-btn--gold:hover{background:var(--gold-light);}
.a-btn--navy{background:var(--navy-deep);color:rgba(255,255,255,.5);border:1px solid rgba(255,255,255,.08);}
.a-btn--navy:hover{border-color:var(--gold-rule);color:var(--gold-light);}
.a-btn--ghost{background:transparent;color:rgba(255,255,255,.28);border:1px solid transparent;font-size:.73rem;padding:.7rem 1.4rem;}
.a-btn--ghost:hover{color:rgba(255,255,255,.55);}

/* Checklist box */
.apply-checklist{border:1px solid var(--gold-rule);background:rgba(166,138,74,.06);padding:1.75rem;}
.acl-title{font-family:var(--mono);font-size:.58rem;letter-spacing:.25em;text-transform:uppercase;color:var(--gold);margin-bottom:1.25rem;padding-bottom:.85rem;border-bottom:1px solid var(--gold-rule);}
.acl-item{display:flex;align-items:center;gap:.75rem;padding:.6rem 0;border-bottom:1px solid var(--rule);}
.acl-item:last-child{border-bottom:none;}
.acl-check{width:18px;height:18px;border:1px solid var(--gold-rule);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.acl-check i{font-size:.55rem;color:var(--gold);}
.acl-text{font-size:.78rem;color:rgba(255,255,255,.5);font-weight:300;}
.acl-text strong{font-weight:500;color:var(--white);display:block;margin-bottom:.08rem;}

/* Footer */
.site-footer{background:var(--navy-deep);padding:3rem 7vw;border-top:1px solid var(--rule);}
.footer-inner{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1.5rem;}
.footer-mark{font-family:var(--serif);font-size:.9rem;font-weight:300;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.3);}
.footer-mark em{font-style:normal;color:var(--gold);}
.footer-legal{font-family:var(--mono);font-size:.58rem;letter-spacing:.1em;color:rgba(255,255,255,.18);line-height:1.6;max-width:600px;text-align:right;}

/* RESPONSIVE */
@media(max-width:1100px){
    .hero-inner,.elig-grid,.faq-2col,.apply-content{grid-template-columns:1fr;}
    .hero-panel,.apply-rings{display:none;}
    .config-grid{grid-template-columns:1fr;}
    .cfg-card{border-right:none;border-bottom:1px solid var(--rule);}
    .cfg-card:last-child{border-bottom:none;}
    .obligations-grid{grid-template-columns:1fr 1fr;}
    .ob-card{border-right:none;border-bottom:1px solid var(--rule-dark);}
    .ob-card:nth-child(2n){border-right:none;}
    .ob-card:nth-child(2n-1){border-right:1px solid var(--rule-dark);}
    .ob-card:last-child,.ob-card:nth-last-child(2){border-bottom:none;}
    .consequence-strip{grid-template-columns:1fr;}
    .why-grid,.process-header,.config-header,.ongoing-header{grid-template-columns:1fr;}
    .apply-checklist{display:none;}
}
@media(max-width:900px){
    .nav-links{display:none;}
    .p-step{grid-template-columns:80px 1fr;grid-template-rows:auto auto;}
    .p-step-right{grid-column:2;}
    .hero-strip-inner{grid-template-columns:repeat(2,1fr);gap:1.5rem;}
    .hs-item{border-right:none;border-bottom:1px solid var(--rule);padding-bottom:1rem;}
    .hs-item:nth-child(odd){border-right:1px solid var(--rule);}
}
@media(max-width:600px){
    .obligations-grid{grid-template-columns:1fr;}
    .ob-card{border-right:none;}
    .ob-card:nth-child(2n-1){border-right:none;}
    .hero-strip-inner{grid-template-columns:1fr 1fr;}
    .p-step{grid-template-columns:60px 1fr;}
    .p-step-num{font-size:3.5rem;}
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="site-nav">
    <a href="/" class="nav-wordmark"><em>Old</em> Union</a>
    <ul class="nav-links">
        <li><a href="/">Home</a></li>
        <li><a href="/how-it-works.php">How It Works</a></li>
        <li><a href="/listing-center.php" class="active">Listing Centre</a></li>
        <li><a href="/app/discover/">Discover</a></li>
    </ul>
    <a href="/app/company/create.php" class="nav-cta">Apply to List</a>
</nav>

<!-- HERO -->
<section class="hero">
    <div class="container">
        <div class="hero-inner">
            <div>
                <div class="hero-label">Old Union · Listing Centre</div>
                <h1 class="hero-heading">Raise capital from<em>your community.</em></h1>
                <p class="hero-body">Old Union gives verified township and community businesses access to structured, patient capital — without surrendering equity, control, or dignity. List your business, configure a campaign, and raise from up to 50 contributors under South African private placement law.</p>
                <div class="hero-actions">
                    <a href="/app/company/create.php" class="hero-btn hero-btn--gold">Apply to List <i class="fa-solid fa-arrow-right"></i></a>
                    <a href="#section-process" class="hero-btn hero-btn--outline">View Requirements <i class="fa-solid fa-chevron-down"></i></a>
                </div>
            </div>
            <aside class="hero-panel">
                <div class="hero-panel-title">What You Get on Old Union</div>
                <div class="hp-row"><i class="fa-solid fa-building-check hp-icon"></i><div><div class="hp-label">Verified Listing</div><div class="hp-sub">KYC-cleared profile, publicly discoverable on the platform</div></div></div>
                <div class="hp-row"><i class="fa-solid fa-sack-dollar hp-icon"></i><div><div class="hp-label">Raise up to R 10M</div><div class="hp-sub">Per campaign, via private placement exemption</div></div></div>
                <div class="hp-row"><i class="fa-solid fa-users hp-icon"></i><div><div class="hp-label">Up to 50 Contributors</div><div class="hp-sub">Per raise, per SA Companies Act regulations</div></div></div>
                <div class="hp-row"><i class="fa-solid fa-file-contract hp-icon"></i><div><div class="hp-label">Legally Structured Agreements</div><div class="hp-sub">Platform-drafted, SA law governed, enforceable</div></div></div>
                <div class="hp-row"><i class="fa-solid fa-vault hp-icon"></i><div><div class="hp-label">Escrow Administration</div><div class="hp-sub">Funds held securely until minimum raise confirmed</div></div></div>
                <div class="hp-row"><i class="fa-solid fa-rotate hp-icon"></i><div><div class="hp-label">Subsequent Raises</div><div class="hp-sub">Build a track record and raise again on the platform</div></div></div>
            </aside>
        </div>
    </div>
    <div class="hero-strip">
        <div class="container">
            <div class="hero-strip-inner">
                <div class="hs-item"><div class="hs-num">R<span>0</span></div><div class="hs-label">Upfront listing fee<br>Free to apply</div></div>
                <div class="hs-item"><div class="hs-num">50<sup>max</sup></div><div class="hs-label">Contributors per<br>campaign</div></div>
                <div class="hs-item"><div class="hs-num">1–3</div><div class="hs-label">Business day KYC<br>review turnaround</div></div>
                <div class="hs-item"><div class="hs-num">3</div><div class="hs-label">Instrument types<br>available</div></div>
            </div>
        </div>
    </div>
</section>
<div class="gold-rule-line"></div>

<!-- WHY LIST -->
<section class="why-section">
    <div class="container">
        <div class="why-grid">
            <div>
                <div class="eyebrow eyebrow--dark">Why Old Union</div>
                <h2 class="why-heading">Patient capital,<br>built for <em>you.</em></h2>
                <p class="why-body">Most township businesses don't fail for lack of ambition or work ethic. They fail because formal capital markets were never designed for them — too much paperwork, too little trust, and terms that hand away the very business you built. Old Union was designed to change that equation. Raise from people who know your community, on terms you control, without giving up a single share.</p>
                <div class="why-quote">
                    "The Old Union model creates a direct financial relationship between a business and the people who believe in it. That is not just good finance — it is good community-building."
                    <cite>— Old Union Platform Philosophy</cite>
                </div>
            </div>
            <div class="why-benefits">
                <div class="benefit-row"><div class="benefit-icon"><i class="fa-solid fa-shield-halved"></i></div><div><div class="benefit-title">Retain full operational control</div><div class="benefit-desc">No equity transfer, no board seats, no dilution. You remain in charge of your business — contributors are financial partners, not owners.</div></div></div>
                <div class="benefit-row"><div class="benefit-icon"><i class="fa-solid fa-coins"></i></div><div><div class="benefit-title">Flexible return structures</div><div class="benefit-desc">Choose from revenue share, cooperative membership, or fixed return loan — whichever instrument matches your business model and cash flow.</div></div></div>
                <div class="benefit-row"><div class="benefit-icon"><i class="fa-solid fa-building-check"></i></div><div><div class="benefit-title">The verified badge builds trust</div><div class="benefit-desc">The Old Union verification process is thorough by design. Completing it signals to contributors that your business is real, compliant, and transparent.</div></div></div>
                <div class="benefit-row"><div class="benefit-icon"><i class="fa-solid fa-file-contract"></i></div><div><div class="benefit-title">Platform-administered agreements</div><div class="benefit-desc">We handle the legal structuring, agreement drafting, escrow administration, and distribution calculations. You focus on running the business.</div></div></div>
                <div class="benefit-row"><div class="benefit-icon"><i class="fa-solid fa-rotate"></i></div><div><div class="benefit-title">Raise again, and again</div><div class="benefit-desc">A strong first campaign, with consistent reporting and distributions, builds the track record that makes your next raise even easier.</div></div></div>
                <div class="benefit-row"><div class="benefit-icon"><i class="fa-solid fa-ban"></i></div><div><div class="benefit-title">Free to list — no upfront fees</div><div class="benefit-desc">No listing fees, no escrow charges on failed campaigns. Old Union earns a success fee on closed campaigns only — we eat what we kill.</div></div></div>
            </div>
        </div>
    </div>
</section>

<!-- ELIGIBILITY & REQUIREMENTS -->
<section class="eligibility-section" id="section-requirements">
    <div class="container">
        <div class="elig-grid">
            <div class="elig-sidebar">
                <div class="eyebrow eyebrow--light">Eligibility &amp; Requirements</div>
                <h2 class="elig-heading">What you'll<br>need to <em>list.</em></h2>
                <p class="elig-intro">The Old Union verification process is the foundation of trust on the platform. It exists to protect contributors and to give your business credibility. Here is a complete breakdown of what is required and what is recommended.</p>
                <div class="elig-note">
                    <strong style="color:rgba(255,255,255,.5);display:block;margin-bottom:.35rem;font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;font-family:var(--mono);">Before You Apply</strong>
                    Your business must be registered in South Africa and have at least one active director. Sole proprietors and informal businesses are currently not supported. Cooperatives, SMEs, startups, NGOs, and social enterprises are all eligible.
                </div>
            </div>
            <div>
                <div class="req-table">

                    <div class="req-category">
                        <div class="req-cat-title"><i class="fa-solid fa-building"></i> Company Registration</div>
                        <div class="req-rows">
                            <div class="req-row">
                                <div><div class="req-name">CIPC Registration Certificate</div><div class="req-desc">Certificate of incorporation from the Companies and Intellectual Property Commission</div></div>
                                <span class="req-badge req-badge--req">Required</span>
                                <div class="req-status req-status--req"><i class="fa-solid fa-check"></i></div>
                            </div>
                            <div class="req-row">
                                <div><div class="req-name">Company Registration Number</div><div class="req-desc">Your unique CIPC registration identifier (e.g. 2023/123456/07)</div></div>
                                <span class="req-badge req-badge--req">Required</span>
                                <div class="req-status req-status--req"><i class="fa-solid fa-check"></i></div>
                            </div>
                            <div class="req-row">
                                <div><div class="req-name">Memorandum of Incorporation (MOI)</div><div class="req-desc">For cooperative and NGO structures — governing documents required</div></div>
                                <span class="req-badge req-badge--rec">If Applicable</span>
                                <div class="req-status req-status--rec"><i class="fa-solid fa-minus"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="req-category">
                        <div class="req-cat-title"><i class="fa-solid fa-id-card"></i> Director Identity</div>
                        <div class="req-rows">
                            <div class="req-row">
                                <div><div class="req-name">Director / CEO ID Document</div><div class="req-desc">South African ID, passport, or driver's licence of the primary director</div></div>
                                <span class="req-badge req-badge--req">Required</span>
                                <div class="req-status req-status--req"><i class="fa-solid fa-check"></i></div>
                            </div>
                            <div class="req-row">
                                <div><div class="req-name">Selfie / Liveness Check</div><div class="req-desc">Photo verification to match against the ID document submitted</div></div>
                                <span class="req-badge req-badge--req">Required</span>
                                <div class="req-status req-status--req"><i class="fa-solid fa-check"></i></div>
                            </div>
                            <div class="req-row">
                                <div><div class="req-name">Additional Director IDs</div><div class="req-desc">For companies with multiple directors — recommended for full KYC compliance</div></div>
                                <span class="req-badge req-badge--rec">Recommended</span>
                                <div class="req-status req-status--rec"><i class="fa-solid fa-minus"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="req-category">
                        <div class="req-cat-title"><i class="fa-solid fa-map-pin"></i> Business Address</div>
                        <div class="req-rows">
                            <div class="req-row">
                                <div><div class="req-name">Proof of Business Address</div><div class="req-desc">Utility bill, lease agreement, or bank statement — not older than 3 months</div></div>
                                <span class="req-badge req-badge--req">Required</span>
                                <div class="req-status req-status--req"><i class="fa-solid fa-check"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="req-category">
                        <div class="req-cat-title"><i class="fa-solid fa-receipt"></i> Tax Status</div>
                        <div class="req-rows">
                            <div class="req-row">
                                <div><div class="req-name">SARS Tax Clearance Certificate</div><div class="req-desc">Good standing certificate or tax clearance pin from SARS</div></div>
                                <span class="req-badge req-badge--rec">Recommended</span>
                                <div class="req-status req-status--rec"><i class="fa-solid fa-minus"></i></div>
                            </div>
                            <div class="req-row">
                                <div><div class="req-name">Income Tax Number</div><div class="req-desc">Required to submit a tax clearance certificate</div></div>
                                <span class="req-badge req-badge--rec">If Applicable</span>
                                <div class="req-status req-status--rec"><i class="fa-solid fa-minus"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="req-category">
                        <div class="req-cat-title"><i class="fa-solid fa-chart-bar"></i> Financial Disclosures</div>
                        <div class="req-rows">
                            <div class="req-row">
                                <div><div class="req-name">Revenue &amp; Profit History</div><div class="req-desc">At least 6 months of self-reported monthly financials uploaded on the platform</div></div>
                                <span class="req-badge req-badge--rec">Recommended</span>
                                <div class="req-status req-status--rec"><i class="fa-solid fa-minus"></i></div>
                            </div>
                            <div class="req-row">
                                <div><div class="req-name">Accountant-Verified Statements</div><div class="req-desc">Verification by a registered accountant unlocks a higher trust badge</div></div>
                                <span class="req-badge req-badge--rec">Recommended</span>
                                <div class="req-status req-status--rec"><i class="fa-solid fa-minus"></i></div>
                            </div>
                            <div class="req-row">
                                <div><div class="req-name">Audited Annual Statements</div><div class="req-desc">Highest trust tier — audited financials unlock the full Verified badge</div></div>
                                <span class="req-badge req-badge--rec">Optional</span>
                                <div class="req-status req-status--rec"><i class="fa-solid fa-minus"></i></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>

<!-- LISTING PROCESS -->
<section class="process-section" id="section-process">
    <div class="container">
        <div class="process-header">
            <div>
                <div class="eyebrow eyebrow--dark">The Listing Process</div>
                <h2 class="process-heading">Five stages from<br><em>application to capital.</em></h2>
            </div>
            <p class="process-sub">Each stage is platform-guided with clear instructions, document templates, and our team available to assist. Most businesses complete the process in under two weeks.</p>
        </div>

        <div class="process-steps">

            <div class="p-step">
                <div class="p-step-num">01</div>
                <div class="p-step-left">
                    <div class="p-step-label">Stage One</div>
                    <h3 class="p-step-title">Create Your Company Profile</h3>
                    <p class="p-step-desc">Register your business on the platform using our guided onboarding wizard. You'll complete your company details, industry classification, stage, location, and write your business description. Upload your pitch deck and any supporting materials. Everything can be saved and revisited — no need to complete in one session.</p>
                    <div class="p-step-tag-row">
                        <span class="p-step-tag">Takes 20–30 minutes</span>
                        <span class="p-step-tag">Save &amp; continue any time</span>
                        <span class="p-step-tag p-step-tag--gold">Free to register</span>
                    </div>
                </div>
                <div class="p-step-right">
                    <div class="p-step-detail-title">What you'll complete in this stage</div>
                    <div class="p-step-items">
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Basic information</strong> Company name, type, industry, stage, founding year</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Contact details</strong> Business email, phone, website</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Location</strong> Province, municipality, city, area type (urban/township/rural)</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Pitch narrative</strong> Problem, solution, business model, traction, team</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Key highlights</strong> Up to 8 headline stats shown on your listing card</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Branding</strong> Logo and banner image upload</div></div>
                    </div>
                </div>
            </div>

            <div class="p-step-connector"><i class="fa-solid fa-arrow-down"></i><span>Submit for KYC review</span></div>

            <div class="p-step">
                <div class="p-step-num">02</div>
                <div class="p-step-left">
                    <div class="p-step-label">Stage Two</div>
                    <h3 class="p-step-title">Submit KYC Documents</h3>
                    <p class="p-step-desc">Upload your verification documents through the secure document portal in your company dashboard. The Old Union compliance team reviews each submission manually — this is how your listing earns the "Verified" badge that contributors rely on. You will receive feedback via email within 1–3 business days.</p>
                    <div class="p-step-tag-row">
                        <span class="p-step-tag">1–3 business day review</span>
                        <span class="p-step-tag">Email notification on outcome</span>
                        <span class="p-step-tag">Resubmit if any document needs updating</span>
                    </div>
                </div>
                <div class="p-step-right">
                    <div class="p-step-detail-title">Documents submitted at this stage</div>
                    <div class="p-step-items">
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>CIPC Registration Certificate</strong> Required — core business identity</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Director ID &amp; Selfie</strong> Required — KYC identity verification</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Proof of Business Address</strong> Required — not older than 3 months</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Tax Clearance Certificate</strong> Recommended — SARS good standing</div></div>
                    </div>
                </div>
            </div>

            <div class="p-step-connector"><i class="fa-solid fa-arrow-down"></i><span>Verification complete — configure campaign</span></div>

            <div class="p-step">
                <div class="p-step-num">03</div>
                <div class="p-step-left">
                    <div class="p-step-label">Stage Three</div>
                    <h3 class="p-step-title">Configure Your Campaign</h3>
                    <p class="p-step-desc">Once verified, you unlock the campaign creation wizard. Choose your instrument type, set your financial targets, define contributor limits, and configure your deal terms. Old Union's campaign wizard walks you through every field with contextual guidance — no financial or legal expertise required.</p>
                    <div class="p-step-tag-row">
                        <span class="p-step-tag">3 instrument types</span>
                        <span class="p-step-tag">Max 50 contributors</span>
                        <span class="p-step-tag p-step-tag--gold">Old Union reviews before opening</span>
                    </div>
                </div>
                <div class="p-step-right">
                    <div class="p-step-detail-title">Fields you'll configure in this stage</div>
                    <div class="p-step-items">
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Campaign title &amp; tagline</strong> The headline contributors see on the listing</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Instrument type</strong> Revenue share, co-op membership, or fixed return loan</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Raise target &amp; minimum</strong> How much you want vs. the floor for disbursement</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Contributor limits</strong> Minimum contribution, maximum contribution, contributor cap</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Timeline</strong> Opening date and closing date</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Deal terms</strong> Revenue share %, duration, unit price, or return rate</div></div>
                    </div>
                </div>
            </div>

            <div class="p-step-connector"><i class="fa-solid fa-arrow-down"></i><span>Old Union reviews campaign — 2–4 business days</span></div>

            <div class="p-step">
                <div class="p-step-num">04</div>
                <div class="p-step-left">
                    <div class="p-step-label">Stage Four</div>
                    <h3 class="p-step-title">Campaign Opens &amp; Contributions Flow In</h3>
                    <p class="p-step-desc">After Old Union approves your campaign, it goes live on the platform on your configured opening date. Contributors discover your listing, review your pitch and financials, and submit contributions. You can post updates, answer Q&A questions, and monitor contributor count and funds raised in real time through your company dashboard.</p>
                    <div class="p-step-tag-row">
                        <span class="p-step-tag">Live dashboard visibility</span>
                        <span class="p-step-tag">Mandatory Q&amp;A responses</span>
                        <span class="p-step-tag">Funds held in escrow</span>
                    </div>
                </div>
                <div class="p-step-right">
                    <div class="p-step-detail-title">Your responsibilities while live</div>
                    <div class="p-step-items">
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Answer Q&amp;A publicly</strong> Contributors can post questions — timely responses build confidence</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Post campaign updates</strong> Keep your audience informed with regular updates</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Monitor contributor count</strong> Track the 50-person cap and adjust your outreach</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Keep financials current</strong> Fresh financials increase contributor confidence</div></div>
                    </div>
                </div>
            </div>

            <div class="p-step-connector"><i class="fa-solid fa-arrow-down"></i><span>Campaign closes — minimum raise confirmed</span></div>

            <div class="p-step">
                <div class="p-step-num">05</div>
                <div class="p-step-left">
                    <div class="p-step-label">Stage Five</div>
                    <h3 class="p-step-title">Receive Funds &amp; Activate Agreements</h3>
                    <p class="p-step-desc">Once your campaign closes and the minimum raise is confirmed, funds are released from escrow to your nominated business bank account within 2–4 business days. Your investment agreements activate simultaneously. Contributors begin tracking your progress. You begin your reporting and distribution obligations.</p>
                    <div class="p-step-tag-row">
                        <span class="p-step-tag">2–4 business day disbursement</span>
                        <span class="p-step-tag">Agreements auto-activate</span>
                        <span class="p-step-tag p-step-tag--gold">Monthly reporting begins</span>
                    </div>
                </div>
                <div class="p-step-right">
                    <div class="p-step-detail-title">What happens at disbursement</div>
                    <div class="p-step-items">
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Funds released to your account</strong> Net of Old Union's platform success fee</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Agreements signed &amp; activated</strong> All contributor agreements become legally binding</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Contributors notified</strong> Dashboard updates reflect active status for all contributors</div></div>
                        <div class="p-step-item"><div class="p-step-item-dot"></div><div><strong>Reporting cycle begins</strong> First monthly report due at end of month one</div></div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- CAMPAIGN CONFIGURATION -->
<section class="config-section">
    <div class="container">
        <div class="config-header">
            <div>
                <div class="eyebrow eyebrow--light">Campaign Configuration</div>
                <h2 class="config-heading">Choose your<br><em>instrument.</em></h2>
            </div>
            <p class="config-sub">The right instrument depends on your business model, cash flow pattern, and what your contributor community values. Our campaign wizard guides you through every field — but here is an overview of each option.</p>
        </div>
        <div class="config-grid">
            <div class="cfg-card">
                <div class="cfg-card-icon"><i class="fa-solid fa-chart-line"></i></div>
                <div class="cfg-card-name">Revenue Share</div>
                <p class="cfg-card-desc">Contributors receive a fixed percentage of your monthly reported revenue, proportional to their investment, for a set number of months. Best for businesses with consistent, trackable monthly revenue.</p>
                <div class="cfg-detail-label">Fields you'll configure</div>
                <div class="cfg-fields">
                    <div class="cfg-field"><span>Revenue share %</span><span>e.g. 8% per month</span></div>
                    <div class="cfg-field"><span>Duration</span><span>12–60 months</span></div>
                    <div class="cfg-field"><span>Raise target</span><span>Your funding goal</span></div>
                    <div class="cfg-field"><span>Min. raise</span><span>Floor for disbursement</span></div>
                    <div class="cfg-field"><span>Contributor limits</span><span>Min / max / cap (50)</span></div>
                    <div class="cfg-field"><span>Campaign timeline</span><span>Open / close dates</span></div>
                </div>
            </div>
            <div class="cfg-card">
                <div class="cfg-card-icon"><i class="fa-solid fa-people-roof"></i></div>
                <div class="cfg-card-name">Cooperative Membership</div>
                <p class="cfg-card-desc">Contributors purchase membership units in your cooperative at a fixed unit price. Designed for worker cooperatives, community cooperatives, and community-owned township businesses.</p>
                <div class="cfg-detail-label">Fields you'll configure</div>
                <div class="cfg-fields">
                    <div class="cfg-field"><span>Unit name</span><span>e.g. Community Share</span></div>
                    <div class="cfg-field"><span>Unit price</span><span>Fixed price per unit</span></div>
                    <div class="cfg-field"><span>Units available</span><span>Total membership slots</span></div>
                    <div class="cfg-field"><span>Raise target</span><span>Units × unit price</span></div>
                    <div class="cfg-field"><span>Min. raise</span><span>Floor for disbursement</span></div>
                    <div class="cfg-field"><span>Campaign timeline</span><span>Open / close dates</span></div>
                </div>
            </div>
            <div class="cfg-card">
                <div class="cfg-card-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                <div class="cfg-card-name">Fixed Return Loan</div>
                <p class="cfg-card-desc">A structured loan with a fixed annual return rate and predetermined repayment schedule. Best for established businesses with stable cash flow who can commit to fixed payment obligations.</p>
                <div class="cfg-detail-label">Fields you'll configure</div>
                <div class="cfg-fields">
                    <div class="cfg-field"><span>Return rate</span><span>Fixed % per annum</span></div>
                    <div class="cfg-field"><span>Loan term</span><span>6–36 months</span></div>
                    <div class="cfg-field"><span>Raise target</span><span>Total loan amount</span></div>
                    <div class="cfg-field"><span>Min. raise</span><span>Floor for disbursement</span></div>
                    <div class="cfg-field"><span>Contributor limits</span><span>Min / max / cap (50)</span></div>
                    <div class="cfg-field"><span>Campaign timeline</span><span>Open / close dates</span></div>
                </div>
            </div>
        </div>
        <div class="review-callout">
            <div class="rc-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div>
                <div class="rc-title">Every campaign is reviewed by Old Union before going live</div>
                <p class="rc-body">After you submit your campaign, the Old Union team conducts a 2–4 business day review to confirm the deal terms are appropriate, legally sound, and clearly presented. We may request clarifications or adjustments before approval. This process protects both businesses and contributors.</p>
            </div>
        </div>
    </div>
</section>

<!-- ONGOING OBLIGATIONS -->
<section class="ongoing-section">
    <div class="container">
        <div class="ongoing-header">
            <div>
                <div class="eyebrow eyebrow--dark">Ongoing Obligations</div>
                <h2 class="ongoing-heading">After you raise —<br>what <em>comes next.</em></h2>
            </div>
            <p class="ongoing-sub">Raising capital is the beginning of the relationship, not the end of it. Old Union's platform is designed to make ongoing obligations manageable — the dashboard handles most of the administration. Here is what is expected of listed businesses.</p>
        </div>
        <div class="obligations-grid">
            <div class="ob-card">
                <div class="ob-label">Monthly</div>
                <div class="ob-title">Financial Reporting</div>
                <div class="ob-desc">Post your revenue and financial summary every month through the company dashboard. Contributors receive these updates automatically.</div>
                <div class="ob-items">
                    <div class="ob-item">Revenue this period</div>
                    <div class="ob-item">Gross profit</div>
                    <div class="ob-item">Operating expenses</div>
                    <div class="ob-item">Net profit / loss</div>
                </div>
            </div>
            <div class="ob-card">
                <div class="ob-label">Per Schedule</div>
                <div class="ob-title">Payout Distributions</div>
                <div class="ob-desc">Distribute returns to contributors according to your agreement schedule. The platform calculates each contributor's entitlement automatically.</div>
                <div class="ob-items">
                    <div class="ob-item">Platform calculates share</div>
                    <div class="ob-item">You confirm the revenue figure</div>
                    <div class="ob-item">Platform distributes to wallets</div>
                </div>
            </div>
            <div class="ob-card">
                <div class="ob-label">As Reached</div>
                <div class="ob-title">Milestone Updates</div>
                <div class="ob-desc">Post significant company milestones — new partnerships, customer milestones, awards, geographic expansion. These appear on your public profile.</div>
                <div class="ob-items">
                    <div class="ob-item">Visible on public listing</div>
                    <div class="ob-item">Builds contributor trust</div>
                    <div class="ob-item">Strengthens future raises</div>
                </div>
            </div>
            <div class="ob-card">
                <div class="ob-label">As Required</div>
                <div class="ob-title">Q&amp;A Responses</div>
                <div class="ob-desc">Contributors can post questions on your campaign at any time. You are expected to respond within 5 business days. All Q&amp;A is public.</div>
                <div class="ob-items">
                    <div class="ob-item">Public &amp; permanent</div>
                    <div class="ob-item">Builds platform credibility</div>
                    <div class="ob-item">Failure flagged by Old Union</div>
                </div>
            </div>
        </div>
        <div class="consequence-strip">
            <div class="consequence-card">
                <div class="cc-title"><i class="fa-solid fa-circle-check"></i> If you meet your obligations</div>
                <p class="cc-body">Consistent reporting and on-time distributions build a track record on the platform. Contributors can see your history — this makes your next raise significantly easier. High-performing businesses earn a "Track Record" trust badge and may qualify for expedited campaign review.</p>
            </div>
            <div class="consequence-card">
                <div class="cc-title"><i class="fa-solid fa-triangle-exclamation"></i> If obligations are not met</div>
                <p class="cc-body">Non-compliant businesses are flagged on the platform. Repeated failures to report or distribute can result in suspension of the company profile and, where warranted, escalation to Old Union's legal partners. Contributor agreements are enforceable under South African law.</p>
            </div>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="listing-faq">
    <div class="container">
        <div class="faq-2col">
            <div class="faq-2col-left">
                <div class="eyebrow eyebrow--light">Business FAQs</div>
                <h2 class="faq-sidebar-heading">Your<br>questions,<br><em>answered.</em></h2>
                <p class="faq-sidebar-body">Common questions from founders and business owners who are considering listing on Old Union. Don't see your question here? Reach out to our team directly.</p>
                <div class="faq-contact"><i class="fa-solid fa-envelope"></i> <span>Email us at <a href="mailto:listing@oldunion.co.za">listing@oldunion.co.za</a></span></div>
            </div>
            <div>
                <div class="faq-list">
                    <div class="faq-item open"><div class="faq-q" onclick="toggleFaq(this)"><span class="faq-q-txt">Does listing mean I give up equity in my business?</span><div class="faq-tog"><i class="fa-solid fa-chevron-down"></i></div></div><div class="faq-ans"><div class="faq-ans-inner">No. Old Union does not facilitate equity raises. None of the three available instruments (revenue share, cooperative membership, fixed return loan) involve the transfer of equity or ownership shares in your business. Contributors become financial partners — not owners. You retain full operational control.</div></div></div>
                    <div class="faq-item"><div class="faq-q" onclick="toggleFaq(this)"><span class="faq-q-txt">What does Old Union charge for a successful raise?</span><div class="faq-tog"><i class="fa-solid fa-chevron-down"></i></div></div><div class="faq-ans"><div class="faq-ans-inner">Old Union charges a platform success fee on successfully closed campaigns — a percentage of the total funds raised, deducted at disbursement. There are no upfront fees, no listing fees, no escrow charges on failed campaigns, and no monthly platform subscription. The exact success fee percentage is disclosed during campaign configuration.</div></div></div>
                    <div class="faq-item"><div class="faq-q" onclick="toggleFaq(this)"><span class="faq-q-txt">How long does the full process take from application to funds?</span><div class="faq-tog"><i class="fa-solid fa-chevron-down"></i></div></div><div class="faq-ans"><div class="faq-ans-inner">The timeline depends on the complexity of your documents and how long your campaign runs. Typically: profile creation (1–2 days), KYC review (1–3 business days), campaign configuration (1 day), Old Union campaign review (2–4 business days), campaign live period (your configured duration — typically 30–90 days), disbursement (2–4 business days). Total from application to funds: approximately 6–12 weeks depending on campaign duration.</div></div></div>
                    <div class="faq-item"><div class="faq-q" onclick="toggleFaq(this)"><span class="faq-q-txt">Can I list my business if it's not yet profitable?</span><div class="faq-tog"><i class="fa-solid fa-chevron-down"></i></div></div><div class="faq-ans"><div class="faq-ans-inner">Yes. Profitability is not a listing requirement. Old Union does require that you have a legally registered business and that your financials — whatever they are — are disclosed honestly. Pre-revenue or early-stage businesses can list, but contributors will evaluate that information when deciding whether to contribute. Transparency is more valuable than polish.</div></div></div>
                    <div class="faq-item"><div class="faq-q" onclick="toggleFaq(this)"><span class="faq-q-txt">What if my campaign fails to reach the minimum raise?</span><div class="faq-tog"><i class="fa-solid fa-chevron-down"></i></div></div><div class="faq-ans"><div class="faq-ans-inner">If your campaign closes without reaching its declared minimum raise, the campaign is cancelled automatically. All contributors receive full refunds to their Old Union wallets — no fees are deducted. You may reconfigure your campaign terms and relaunch. A failed campaign does not prevent you from raising again on the platform.</div></div></div>
                    <div class="faq-item"><div class="faq-q" onclick="toggleFaq(this)"><span class="faq-q-txt">Can I run multiple campaigns at the same time?</span><div class="faq-tog"><i class="fa-solid fa-chevron-down"></i></div></div><div class="faq-ans"><div class="faq-ans-inner">You may have only one active open campaign at a time per company profile. Once a campaign closes (successfully or otherwise), you may configure and launch a new campaign. There is no limit to the total number of campaigns a business can run over its lifetime on the platform.</div></div></div>
                    <div class="faq-item"><div class="faq-q" onclick="toggleFaq(this)"><span class="faq-q-txt">Does Old Union verify every piece of information on my profile?</span><div class="faq-tog"><i class="fa-solid fa-chevron-down"></i></div></div><div class="faq-ans"><div class="faq-ans-inner">Old Union verifies your KYC documents (registration, identity, address) and reviews your campaign terms for legal soundness. Financial disclosures (revenue figures, profit data) are self-reported unless you upload accountant-verified or audited statements. The platform clearly labels each financial disclosure by its verification level — contributors can see whether figures are self-reported or independently verified.</div></div></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- APPLY CTA -->
<section class="apply-section">
    <div class="apply-inner">
        <div class="apply-rings" aria-hidden="true">
            <div class="apply-ring" style="width:200px;height:200px;"></div>
            <div class="apply-ring" style="width:400px;height:400px;"></div>
            <div class="apply-ring" style="width:600px;height:600px;"></div>
            <div class="apply-ring" style="width:800px;height:800px;"></div>
        </div>
        <div class="apply-content">
            <div>
                <p class="apply-pre">Ready to raise from your community?</p>
                <h2 class="apply-heading">Apply to list<em>on Old Union.</em></h2>
                <p class="apply-body">It is free to apply. It takes 20 minutes to create your profile. The verification process is thorough — and that thoroughness is what gives your listing credibility with contributors. Start today.</p>
                <p class="apply-legal">Old Union operates under the Companies Act private placement exemption. Maximum 50 contributors per campaign. All campaigns reviewed and approved by Old Union before going live. A platform success fee applies on successful raises only. This platform does not constitute financial advice.</p>
            </div>
            <div>
                <div class="apply-checklist">
                    <div class="acl-title">Have these ready to apply</div>
                    <div class="acl-item"><div class="acl-check"><i class="fa-solid fa-check"></i></div><div class="acl-text"><strong>CIPC Registration Certificate</strong>Your company registration document</div></div>
                    <div class="acl-item"><div class="acl-check"><i class="fa-solid fa-check"></i></div><div class="acl-text"><strong>Director ID Document</strong>SA ID, passport, or driver's licence</div></div>
                    <div class="acl-item"><div class="acl-check"><i class="fa-solid fa-check"></i></div><div class="acl-text"><strong>Proof of Business Address</strong>Utility bill or lease — max 3 months old</div></div>
                    <div class="acl-item"><div class="acl-check"><i class="fa-solid fa-check"></i></div><div class="acl-text"><strong>Business description &amp; financials</strong>What you do and what you earn</div></div>
                    <div class="acl-item"><div class="acl-check"><i class="fa-solid fa-check"></i></div><div class="acl-text"><strong>Logo &amp; banner image</strong>For your public listing card</div></div>
                </div>
                <div style="margin-top:1.25rem;">
                    <div class="apply-actions">
                        <a href="/app/company/create.php" class="a-btn a-btn--gold"><span>Create Company Profile</span><i class="fa-solid fa-arrow-right"></i></a>
                        <a href="/how-it-works.php" class="a-btn a-btn--navy"><span>Investor perspective →</span><i class="fa-solid fa-arrow-right"></i></a>
                        <a href="mailto:listing@oldunion.co.za" class="a-btn a-btn--ghost"><span>Questions? Email our team</span></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-mark"><em>Old</em> Union · Listing Centre</div>
        <p class="footer-legal">Old Union Co-operative Management (Pty) Ltd · Registered in South Africa · CIPC Compliant · © 2025 Old Union. All rights reserved. This platform does not constitute financial advice or an offer to the public. Private placement exemption applies. Max 50 contributors per campaign.</p>
    </div>
</footer>

<script>
function toggleFaq(q) {
    const item = q.closest('.faq-item');
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach(f => f.classList.remove('open'));
    if (!wasOpen) item.classList.add('open');
}

const obs = new IntersectionObserver(entries => {
    entries.forEach((e, i) => {
        if (e.isIntersecting) {
            setTimeout(() => {
                e.target.style.opacity = '1';
                e.target.style.transform = 'translateY(0)';
            }, i * 60);
            obs.unobserve(e.target);
        }
    });
}, { threshold: 0.08 });

document.querySelectorAll('.benefit-row,.req-row,.p-step,.cfg-card,.ob-card,.consequence-card,.faq-item,.hs-item,.pillar,.acl-item').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(14px)';
    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
    obs.observe(el);
});
</script>
</body>
</html>
