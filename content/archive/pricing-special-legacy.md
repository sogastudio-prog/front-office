# Pricing Special

WP ID: 464
Slug: pricing-special

---

<!-- wp:html -->
<!--
SoloDrive Pricing Landing Page
Target URL: /pricing
Designed for WordPress Gutenberg inside Astra
Notes:
- Paste section-by-section into a Custom HTML block, or keep as one full-page draft.
- Replace button URLs as needed.
- Class naming follows sd- shared primitive style.
-->

<style>
  .sd-pricing-page {
    --sd-text: #111111;
    --sd-text-soft: #5f6368;
    --sd-bg: #ffffff;
    --sd-bg-soft: #f6f7f8;
    --sd-line: #e7e9ec;
    --sd-primary: #2d6ea3;
    --sd-primary-dark: #245986;
    --sd-radius-xl: 24px;
    --sd-radius-lg: 18px;
    --sd-radius-md: 14px;
    --sd-shadow: 0 14px 40px rgba(0,0,0,.06);
    color: var(--sd-text);
  }

  .sd-pricing-page * { box-sizing: border-box; }

  .sd-wrap {
    width: min(1120px, calc(100% - 32px));
    margin: 0 auto;
  }

  .sd-section {
    padding: 72px 0;
  }

  .sd-section-tight {
    padding: 48px 0;
  }

  .sd-kicker {
    font-size: 12px;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--sd-text-soft);
    margin: 0 0 12px;
  }

  .sd-title {
    font-size: clamp(34px, 5vw, 58px);
    line-height: .95;
    letter-spacing: -.04em;
    margin: 0 0 18px;
    max-width: 10ch;
  }

  .sd-title-md {
    font-size: clamp(28px, 4vw, 42px);
    line-height: 1.02;
    letter-spacing: -.035em;
    margin: 0 0 14px;
  }

  .sd-body,
  .sd-copy p {
    font-size: 18px;
    line-height: 1.65;
    color: var(--sd-text-soft);
    margin: 0;
  }

  .sd-copy p + p { margin-top: 14px; }

  .sd-hero {
    padding: 64px 0 56px;
  }

  .sd-hero-grid {
    display: grid;
    grid-template-columns: 1.1fr .9fr;
    gap: 28px;
    align-items: stretch;
  }

  .sd-panel {
    background: var(--sd-bg-soft);
    border: 1px solid var(--sd-line);
    border-radius: var(--sd-radius-xl);
    padding: 28px;
  }

  .sd-panel-white {
    background: #fff;
    box-shadow: var(--sd-shadow);
  }

  .sd-hero-actions,
  .sd-cta-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 26px;
  }

  .sd-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    padding: 0 18px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 700;
    font-size: 14px;
    transition: .18s ease;
  }

  .sd-btn-primary {
    background: var(--sd-primary);
    color: #fff;
  }

  .sd-btn-primary:hover {
    background: var(--sd-primary-dark);
    color: #fff;
  }

  .sd-btn-secondary {
    background: #fff;
    color: var(--sd-primary);
    border: 1px solid var(--sd-line);
  }

  .sd-btn-secondary:hover {
    border-color: #d6d9de;
    color: var(--sd-primary-dark);
  }

  .sd-pricing-card {
    height: 100%;
    display: flex;
    flex-direction: column;
    gap: 22px;
  }

  .sd-plan-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: end;
  }

  .sd-plan-name {
    font-size: 14px;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--sd-text-soft);
    margin: 0 0 8px;
  }

  .sd-price {
    font-size: clamp(38px, 6vw, 62px);
    line-height: .95;
    letter-spacing: -.05em;
    margin: 0;
  }

  .sd-price-note {
    color: var(--sd-text-soft);
    font-size: 14px;
    margin: 6px 0 0;
  }

  .sd-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    background: #eef4f9;
    color: var(--sd-primary-dark);
    font-size: 13px;
    font-weight: 700;
  }

  .sd-divider {
    height: 1px;
    background: var(--sd-line);
  }

  .sd-progress-wrap {
    display: grid;
    gap: 10px;
  }

  .sd-progress-bar {
    position: relative;
    height: 16px;
    border-radius: 999px;
    background: linear-gradient(90deg, #dce9f5 0%, #dce9f5 30%, #eef1f4 30%, #eef1f4 100%);
    overflow: hidden;
  }

  .sd-progress-fill {
    position: absolute;
    inset: 0 auto 0 0;
    width: 30%;
    background: var(--sd-primary);
    border-radius: 999px;
  }

  .sd-progress-scale {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-size: 13px;
    color: var(--sd-text-soft);
  }

  .sd-stat-line {
    font-size: 17px;
    line-height: 1.5;
    color: var(--sd-text);
    font-weight: 700;
  }

  .sd-list,
  .sd-checklist {
    display: grid;
    gap: 12px;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .sd-checklist li,
  .sd-list li {
    position: relative;
    padding-left: 24px;
    color: var(--sd-text-soft);
    line-height: 1.55;
  }

  .sd-checklist li::before,
  .sd-list li::before {
    content: "•";
    position: absolute;
    left: 6px;
    top: 0;
    color: var(--sd-primary);
    font-weight: 700;
  }

  .sd-center {
    text-align: center;
  }

  .sd-max-copy {
    max-width: 760px;
    margin: 0 auto;
  }

  .sd-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
  }

  .sd-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
  }

  .sd-mini-card {
    background: #fff;
    border: 1px solid var(--sd-line);
    border-radius: var(--sd-radius-lg);
    padding: 22px;
  }

  .sd-mini-number {
    font-size: 12px;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--sd-text-soft);
    margin: 0 0 10px;
  }

  .sd-mini-title {
    font-size: 22px;
    line-height: 1.1;
    letter-spacing: -.03em;
    margin: 0 0 10px;
  }

  .sd-mini-copy {
    margin: 0;
    color: var(--sd-text-soft);
    line-height: 1.6;
  }

  .sd-compare {
    background: var(--sd-bg-soft);
    border-radius: var(--sd-radius-xl);
    padding: 28px;
    border: 1px solid var(--sd-line);
  }

  .sd-compare h3 {
    margin: 0 0 14px;
    font-size: 24px;
    letter-spacing: -.03em;
  }

  .sd-quote {
    font-size: clamp(24px, 3vw, 34px);
    line-height: 1.12;
    letter-spacing: -.03em;
    margin: 0;
  }

  .sd-eyebrow {
    font-size: 14px;
    font-weight: 700;
    color: var(--sd-primary-dark);
    margin: 0 0 10px;
  }

  .sd-fine {
    margin-top: 14px;
    font-size: 13px;
    color: var(--sd-text-soft);
  }

  .sd-cta {
    padding: 72px 0 84px;
  }

  .sd-cta-box {
    background: linear-gradient(180deg, #f7f8fa 0%, #eef2f6 100%);
    border: 1px solid var(--sd-line);
    border-radius: 28px;
    padding: 36px 28px;
  }

  @media (max-width: 921px) {
    .sd-hero-grid,
    .sd-grid-3,
    .sd-grid-2 {
      grid-template-columns: 1fr;
    }

    .sd-section,
    .sd-cta {
      padding: 56px 0;
    }

    .sd-hero {
      padding: 40px 0 32px;
    }

    .sd-panel,
    .sd-cta-box,
    .sd-compare,
    .sd-mini-card {
      padding: 22px;
    }

    .sd-wrap {
      width: min(100% - 24px, 1120px);
    }
  }
</style>

<div class="sd-pricing-page">

  <!-- HERO / PRICING -->
  <section class="sd-hero">
    <div class="sd-wrap">
      <div class="sd-hero-grid">

        <div class="sd-panel sd-panel-white">
          <p class="sd-kicker">Pricing</p>
          <h1 class="sd-title">Start with a $300 head start.</h1>
          <div class="sd-copy">
            <p>Your $20/month is not an extra fee.</p>
            <p>It covers your first $300 in monthly bookings. You keep 100% of every ride until you pass it. After that, SoloDrive takes a flat 6.5% application service fee.</p>
          </div>
          <div class="sd-hero-actions">
            <a class="sd-btn sd-btn-primary" href="/request-access/">Get early access</a>
            <a class="sd-btn sd-btn-secondary" href="/invitation-code/">Have an invitation code?</a>
          </div>
          <p class="sd-fine">No contracts. No approval theater. Your storefront can sit live and ready whenever you want to start converting riders.</p>
        </div>

        <div class="sd-panel">
          <div class="sd-pricing-card">
            <div class="sd-plan-row">
              <div>
                <p class="sd-plan-name">SoloDrive Lite</p>
                <h2 class="sd-price">$20<span style="font-size:.42em;font-weight:600;letter-spacing:-.02em;">/mo</span></h2>
                <p class="sd-price-note">Base access. Real infrastructure. Low-friction start.</p>
              </div>
              <div class="sd-badge">0% until $300</div>
            </div>

            <div class="sd-divider"></div>

            <div class="sd-progress-wrap">
              <p class="sd-eyebrow">How it works</p>
              <div class="sd-progress-bar" aria-hidden="true">
                <div class="sd-progress-fill"></div>
              </div>
              <div class="sd-progress-scale">
                <span>$0</span>
                <span>$300</span>
                <span>$1,000+</span>
              </div>
              <p class="sd-stat-line">You keep 100% until you clear your first $300. Then it becomes 6.5%.</p>
            </div>

            <div class="sd-divider"></div>

            <ul class="sd-checklist">
              <li>Your lane stays live</li>
              <li>Your lane is always open for booking</li>
              <li>Your trips and riders stay connected to your lane</li>
              <li>Your lane is always ready to convert riders</li>
              <li>Convert 2–3 good riders and you are already ahead</li>
            </ul>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- WHY IT FEELS DIFFERENT -->
  <section class="sd-section sd-section-tight">
    <div class="sd-wrap sd-center">
      <p class="sd-kicker">Why this works</p>
      <h2 class="sd-title-md sd-max-copy">You are not paying for software. You are activating your own repeat-booking infrastructure.</h2>
      <div class="sd-copy sd-max-copy">
        <p>Most subscriptions feel like overhead. SoloDrive is different. The base version is simply what it costs to keep your lane live, your storefront available, and your direct-booking lane open.</p>
      </div>
    </div>
  </section>

  <!-- 3 VALUE BLOCKS -->
  <section class="sd-section sd-section-tight">
    <div class="sd-wrap">
      <div class="sd-grid-3">
        <div class="sd-mini-card">
          <p class="sd-mini-number">01</p>
          <h3 class="sd-mini-title">Open your lane now</h3>
          <p class="sd-mini-copy">Claim your lane. Keep it open, even before you are running at volume.</p>
        </div>
        <div class="sd-mini-card">
          <p class="sd-mini-number">02</p>
          <h3 class="sd-mini-title">Pay after traction</h3>
          <p class="sd-mini-copy">You are not punished for getting started. The first meaningful chunk of business is already covered.</p>
        </div>
        <div class="sd-mini-card">
          <p class="sd-mini-number">03</p>
          <h3 class="sd-mini-title">Scale without resentment</h3>
          <p class="sd-mini-copy">Once you are moving, the model settles into a flat 6.5% instead of turning growth into a penalty.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- COMPARISON -->
  <section class="sd-section">
    <div class="sd-wrap">
      <div class="sd-grid-2">
        <div class="sd-compare">
          <p class="sd-kicker">Old model</p>
          <h3>Marketplace-controlled access</h3>
          <ul class="sd-list">
            <li>You do the work</li>
            <li>The platform owns the rider</li>
            <li>Pricing and customer access are decided elsewhere</li>
            <li>Your business resets after every completed trip</li>
          </ul>
        </div>
        <div class="sd-compare" style="background:#ffffff; box-shadow: var(--sd-shadow);">
          <p class="sd-kicker">SoloDrive</p>
          <h3>Driver-owned relationship</h3>
          <ul class="sd-list">
            <li>You already have the rider interaction</li>
            <li>You keep the relationship</li>
            <li>You start with a $300 head start</li>
            <li>Your completed rides can become repeat business</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <!-- FUTURE TIERS / UNLOCKS -->
  <section class="sd-section sd-section-tight">
    <div class="sd-wrap sd-center">
      <p class="sd-kicker">Built to expand</p>
      <h2 class="sd-title-md sd-max-copy">Unlocks and volume packages come later. The base version gets you in the game now.</h2>
      <div class="sd-copy sd-max-copy">
        <p>As SoloDrive grows, higher usage, deeper automation, and expanded operating controls can unlock through feature packages and volume tiers. But the homepage message should stay simple: start small, keep your lane open, convert riders, grow from there.</p>
      </div>
    </div>
  </section>

  <!-- ROI / QUOTE -->
  <section class="sd-section">
    <div class="sd-wrap">
      <div class="sd-panel sd-panel-white sd-center">
        <p class="sd-kicker">The real pricing story</p>
        <p class="sd-quote">You do not need a giant customer list to justify SoloDrive. You just need a few riders who already trust you.</p>
      </div>
    </div>
  </section>

  <!-- FAQ STYLE CLARITY -->
  <section class="sd-section sd-section-tight">
    <div class="sd-wrap">
      <div class="sd-grid-2">
        <div class="sd-mini-card">
          <h3 class="sd-mini-title">What is included in Lite?</h3>
          <p class="sd-mini-copy">Lite is the base layer that keeps your storefront, booking flow, and trip surfaces available so you can begin converting direct demand.</p>
        </div>
        <div class="sd-mini-card">
          <h3 class="sd-mini-title">Why charge $20 if the model is performance-based?</h3>
          <p class="sd-mini-copy">Because your infrastructure still needs to exist. The base amount keeps your lane active while still giving you a built-in monthly head start before application fees begin.</p>
        </div>
        <div class="sd-mini-card">
          <h3 class="sd-mini-title">When does the 6.5% begin?</h3>
          <p class="sd-mini-copy">After you pass your first $300 in monthly bookings. Until then, SoloDrive takes 0% application fee.</p>
        </div>
        <div class="sd-mini-card">
          <h3 class="sd-mini-title">Are there bigger plans later?</h3>
          <p class="sd-mini-copy">Yes. Feature unlocks and volume packages can be layered in later for operators who want more automation, more controls, and more throughput.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA -->
  <section class="sd-cta">
    <div class="sd-wrap">
      <div class="sd-cta-box sd-center">
        <p class="sd-kicker">Start here</p>
        <h2 class="sd-title-md" style="max-width: 12ch; margin-left:auto; margin-right:auto;">Start with the riders already in your car.</h2>
        <div class="sd-copy sd-max-copy">
          <p>The point is not to wait until everything is perfect. The point is to open your lane now, keep it live, and start turning completed rides into direct business.</p>
        </div>
        <div class="sd-cta-actions" style="justify-content:center;">
          <a class="sd-btn sd-btn-primary" href="/request-access/">Get early access</a>
          <a class="sd-btn sd-btn-secondary" href="/invitation-code/">Have an invitation code?</a>
        </div>
      </div>
    </div>
  </section>

</div>

<!--
Recommended Gutenberg structure if splitting into blocks:
1. Custom HTML: style block
2. Custom HTML: Hero / pricing section
3. Group block: why it works
4. Columns block or Custom HTML: three value cards
5. Columns block or Custom HTML: comparison
6. Group block: unlocks / future tiers
7. Custom HTML: quote panel
8. Columns block or Custom HTML: FAQ cards
9. Custom HTML: final CTA

Astra page settings:
- Layout: Full Width / Stretched
- Disable title on page if using hero H1
- Content layout: Narrow container OFF
- Top/bottom padding: handled in custom section CSS
-->

<!-- /wp:html -->
