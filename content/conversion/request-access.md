---
type: conversion
template: conversion
title: Request Access
slug: request-access
status: publish
meta_title: Request Access | SoloDrive
meta_description: Request access to SoloDrive and start setting up your own direct-booking ride service page.
summary: Request access to SoloDrive and begin setting up a direct-booking page for your ride service.
primary_cta: continue
conversion_goal: submit_request_access
schema_type: Service
last_reviewed: 2026-04-28
---

<!-- wp:group {"className":"sd-request-page","layout":{"type":"constrained"}} -->
<div class="wp-block-group sd-request-page"><!-- wp:html -->
<!--
SoloDrive Request Access Page
Target URL: /request-access
Designed for Gutenberg + Astra using the consolidated SoloDrive frontend CSS.

Wrapper setup:
- Outer page Group class: sd-request-page

Section pattern:
- Outer Group controls spacing
- Inner Group uses sd-container classes
- Add sd-sign-surface to any inner Group that should show a top-right sign marker
- First element inside sign surface = marker span

Form note:
- Replace the placeholder form block below with your Contact Form 7 shortcode or Gutenberg form block.
-->

<div class="wp-block-group sd-request-page">

  <!-- HERO / INTRO -->
  <div class="wp-block-group sd-section sd-hero" aria-label="Request access hero section">
    <div class="wp-block-group__inner-container sd-container sd-container--narrow sd-sign-surface">
      <span class="sd-sign-marker sd-sign-marker--merge sd-sign-marker--muted" aria-hidden="true"></span>
      <p class="sd-eyebrow">Request Access</p>
      <h1>Open your lane.</h1>
      <p class="sd-lead">You already have riders. This gives you the system to keep them, coordinate them, and bring them back through your own lane.</p>
    </div>
  </div>

  <!-- EXPECTATION SETTING -->
  <div class="wp-block-group sd-section sd-section--plain" aria-label="Who this is for">
    <div class="wp-block-group__inner-container sd-container sd-container--narrow sd-sign-surface">
      <span class="sd-sign-marker sd-sign-marker--hov sd-sign-marker--muted" aria-hidden="true"></span>
      <div class="sd-section-heading">
        <p class="sd-eyebrow">Who this is for</p>
        <h2>Drivers who want their work to start compounding in their direction.</h2>
        <p>SoloDrive is intended for drivers who want to operate under their own brand, convert direct riders, and build repeat business while they continue to drive.</p>
      </div>
      <div class="sd-copy-block">
        <p>No approval theater.</p>
        <p>No worthiness ritual.</p>
        <p>Just a clean way to tell us you want in.</p>
      </div>
    </div>
  </div>

  <!-- WHAT HAPPENS NEXT -->
  <div class="wp-block-group sd-section" aria-label="What happens next">
    <div class="wp-block-group__inner-container sd-container sd-sign-surface">
      <span class="sd-sign-marker sd-sign-marker--exit sd-sign-marker--muted" aria-hidden="true"></span>
      <div class="sd-section-heading">
        <p class="sd-eyebrow">What happens next</p>
        <h2>You request access. We open the next step.</h2>
      </div>
      <div class="wp-block-columns sd-compare-grid">
        <div class="wp-block-column">
          <div class="sd-compare-card">
            <p class="sd-eyebrow">Step one</p>
            <h3>Tell us about your lane</h3>
            <ul class="sd-clean-list">
              <li>Your name and contact info</li>
              <li>Where you operate</li>
              <li>How you currently drive</li>
              <li>What kind of direct business you want to build</li>
            </ul>
          </div>
        </div>
        <div class="wp-block-column">
          <div class="sd-compare-card">
            <p class="sd-eyebrow">Step two</p>
            <h3>We route you forward</h3>
            <ul class="sd-clean-list">
              <li>You get the next step clearly</li>
              <li>You receive access information when ready</li>
              <li>Your onboarding path begins from there</li>
              <li>The goal is movement, not paperwork theater</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- FORM SURFACE -->
  <div class="wp-block-group sd-section sd-section--tight sd-section--panel" aria-label="Access request form">
    <div class="wp-block-group__inner-container sd-container sd-container--narrow">
      <div class="request-access-wrap">

        <div class="request-access-intro">
          <p class="sd-eyebrow">Start here</p>
          <h2>Request access</h2>
          <p>Fill this out once. Keep it simple. We only need enough to understand how you drive now and where your lane is headed.</p>
        </div>

        <div class="request-access-form">
          <h2>Your lane starts here</h2>
          <!-- Replace this block with your actual form shortcode or block -->
          <p>[contact-form-7 id="REQUEST_ACCESS_FORM_ID" title="Request Access"]</p>
        </div>

        <div class="request-access-notes">
          <p><strong>What this is:</strong> a direct path into the next step.</p>
          <p><strong>What this is not:</strong> an application to be judged like a gatekeeping ritual.</p>
        </div>

      </div>
    </div>
  </div>

  <!-- CLARITY / REASSURANCE -->
  <div class="wp-block-group sd-section" aria-label="Request access clarity">
    <div class="wp-block-group__inner-container sd-container sd-sign-surface">
      <span class="sd-sign-marker sd-sign-marker--fork sd-sign-marker--muted" aria-hidden="true"></span>
      <div class="sd-section-heading">
        <p class="sd-eyebrow">Clarity</p>
        <h2>What most drivers want to know before they continue.</h2>
      </div>
      <div class="wp-block-columns sd-compare-grid">
        <div class="wp-block-column">
          <div class="sd-compare-card">
            <p class="sd-eyebrow">Do I need everything figured out first?</p>
            <h3>No.</h3>
            <p>You do not need a perfect plan. You need a clear reason to stop letting every rider disappear.</p>
          </div>
        </div>
        <div class="wp-block-column">
          <div class="sd-compare-card">
            <p class="sd-eyebrow">Is this only for full-time operators?</p>
            <h3>Not necessarily.</h3>
            <p>The real fit is intent. If you want to build your own lane and keep direct riders, this is the right path to start.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- FINAL CTA -->
  <div class="wp-block-group sd-section sd-final-cta" aria-label="Final request access call to action">
    <div class="wp-block-group__inner-container sd-container sd-container--narrow sd-sign-surface">
      <span class="sd-sign-marker sd-sign-marker--merge sd-sign-marker--muted" aria-hidden="true"></span>
      <p class="sd-eyebrow">Keep moving</p>
      <h2>Request access. Then begin onboarding.</h2>
      <p>The whole point is to create forward motion. Once you request access, the next step opens and your lane starts taking shape.</p>
      <div class="sd-actions sd-actions--center">
        <div class="wp-block-button sd-button sd-button--primary"><a class="wp-block-button__link wp-element-button" href="#request-access-form">Continue</a></div>
        <div class="wp-block-button sd-button sd-button--secondary"><a class="wp-block-button__link wp-element-button" href="/pricing/">See pricing</a></div>
      </div>
    </div>
  </div>

</div>

<!--
Gutenberg setup:
1. Outer page Group class: sd-request-page
2. Paste this entire file into a Custom HTML block inside that wrapper, or split section-by-section.
3. Replace the placeholder Contact Form 7 shortcode with your actual form shortcode.
4. If you want the final CTA button to jump to the form, add id="request-access-form" to the form section anchor/wrapper.
5. Sign markers used on this page:
   - Hero: sd-sign-marker--merge
   - Who this is for: sd-sign-marker--hov
   - What happens next: sd-sign-marker--exit
   - Clarity: sd-sign-marker--fork
   - Final CTA: sd-sign-marker--merge
-->
<!-- /wp:html --></div>
<!-- /wp:group -->

<!-- wp:block {"ref":563} /-->