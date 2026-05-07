# SoloDrive Comms Stack — Build Spec v2

## Infrastructure

| Layer | Tool | Notes |
|---|---|---|
| SMS | Quo — +12292322442 | Both stacks, shared number |
| Email | Resend (recommended) | Free tier 3,000/mo · upgrade to Postmark at scale |
| From address | noreply@solodrive.pro | Requires SPF/DKIM/DMARC in Cloudflare |
| Sender display | SoloDrive | On behalf of tenants for runtime events |

---

## Channel Preference Model

### Preference field

Each contact record in the kernel stores `sd_comms_pref` meta:

```php
sd_comms_pref = [
  'channel' => 'sms' | 'email',  // contact's preferred channel
  'email'   => 'email@...',       // required if channel = email
  'phone'   => '+1XXXXXXXXXX',    // required if channel = sms
]
```

### Defaults when no preference is set

| Contact type | Default channel |
|---|---|
| Prospect (operator lead) | Email — B2B, more likely at a computer |
| Operator (live tenant) | Email |
| Customer (rider) | SMS — unless email provided at intake |

### Override rule

Time-critical events bypass preference entirely. These are defined as a static constant in `SD_Comms_Service`:

```php
const SMS_REQUIRED = [
  'T2.1', // Quote ready — customer must act, often ASAP window
  'T2.2', // Booking confirmed — customer expects immediate confirm
  'T2.4', // New ASAP lead — operator must respond in minutes
  'T2.6', // Customer approved — operator must dispatch now
  'T2.7', // Driver en route (future)
  'T2.8', // Driver arrived (future)
];
```

### Routing logic (kernel)

```php
public static function send(string $template_id, array $vars, Contact $contact): void {
    $override = in_array($template_id, self::SMS_REQUIRED, true);
    $pref     = $contact->get_comms_pref();

    if ($override || $pref['channel'] === 'sms' || empty($pref['email'])) {
        self::send_sms($contact->phone, $template_id, $vars);
    } else {
        self::send_email($pref['email'], $template_id, $vars);
    }
}
```

---

## Channel Override Matrix

| ID | Template | Channel | Rationale |
|---|---|---|---|
| T1.1 | Prospect ACK | Email preferred · SMS fallback | B2B, not urgent |
| T1.2 | Activation confirmed | Email preferred · SMS fallback | Detail-heavy, links better in email |
| T1.3 | Connect nudge | Email preferred · SMS fallback | URL-heavy, email more clickable |
| T1.4 | Fully operational | Email preferred · SMS fallback | Non-urgent confirmation |
| **T2.1** | **Quote ready** | **SMS required** | Customer must act — often ASAP |
| **T2.2** | **Booking confirmed** | **SMS required** | Auth success, immediate expected |
| T2.3 | Ride complete | Email preferred · SMS fallback | Receipt — richer in email |
| **T2.4** | **New ASAP lead** | **SMS required** | Operator response window: minutes |
| T2.5 | New reserve lead | Email preferred · SMS fallback | Operator has time to review |
| **T2.6** | **Dispatch** | **SMS required** | Operator must act now |
| T2.7 (future) | Driver en route | SMS required | Real-time status |
| T2.8 (future) | Driver arrived | SMS required | Most time-critical |

---

## Contact Schema

### Quo contact fields

| Field | Values | Notes |
|---|---|---|
| `firstName` | — | — |
| `lastName` | — | — |
| `company` | Tenant slug | Operators and customers |
| `role` | `prospect` / `operator` / `customer` | Channel routing + Sona |
| `email` | RFC-5322 | Stored in Quo + kernel meta |
| `phoneNumber` | E.164 | Always required |

### Kernel meta per record type

- Lead (customer): `sd_comms_pref` on lead post meta
- Tenant (operator): `sd_comms_pref` on tenant post meta
- Prospect: `sd_comms_pref` on prospect post meta

---

## Preference Capture

### Storefront intake — customer (rider)

Add to the intake form:

```
Customer email: [text — optional, unlocks email channel]
Send updates via: ( ) SMS  ( ) Email   ← shown only if email is entered
                   default: SMS if no email given
```

### Prospect form — operator (solodrive.pro)

```
Contact email: [text — required for B2B]
Prefer to hear from us via: ( ) Email  ( ) SMS    ← default: Email
```

---

## Stack 1 — Control Plane (solodrive.pro)

Audience: Prospects + operators (B2B)
Default channel: Email

---

### T1.1 — Prospect ACK

Trigger: Prospect form submitted
Channel: Email preferred · SMS fallback
Timing: Immediately on submit

**SMS:**
```
Hey {{first_name}} — got your SoloDrive request. We'll follow up
with your setup link shortly.

Questions? Reply here. – SoloDrive
```

**Email:**
```
Subject: We got your SoloDrive request, {{first_name}}

Hey {{first_name}},

Thanks for your interest in SoloDrive. We're reviewing your request
and will follow up with your setup link shortly.

Questions? Just reply to this email.

— The SoloDrive Team
```

---

### T1.2 — Activation Confirmed

Trigger: runtime_tenant_id written back from SDPRO/runtime, operator session established
Channel: Email preferred · SMS fallback
Timing: Immediately post-provisioning

**SMS:**
```
You're live, {{first_name}}.

Booking page: solodrive.pro/t/{{slug}}
Dashboard: app.solodrive.pro

Next: complete Stripe Connect in your dashboard.
– SoloDrive
```

**Email:**
```
Subject: You're live on SoloDrive, {{first_name}}

Hey {{first_name}},

Your SoloDrive account is active.

  Booking page:  https://solodrive.pro/t/{{slug}}
  Dashboard:     https://app.solodrive.pro

Complete Stripe Connect in your dashboard so payments route to you.

— SoloDrive
```

---

### T1.3 — Connect Onboarding Nudge

Trigger: 24hr cron post-activation, Stripe Connect incomplete
Channel: Email preferred · SMS fallback
Timing: ~24hr after T1.2 if Connect not done

**SMS:**
```
Hey {{first_name}} — your SoloDrive page is live but Stripe Connect
isn't done. Payments can't reach you until it's complete:
{{connect_url}}
– SoloDrive
```

**Email:**
```
Subject: Action needed — complete Stripe Connect to receive payments

Hey {{first_name}},

Your SoloDrive booking page is live but Stripe Connect isn't finished.
Payments can't reach you until it's complete.

  Finish here: {{connect_url}}

Reply to this email if you run into any trouble.

— SoloDrive
```

---

### T1.4 — Fully Operational

Trigger: Stripe Connect onboarding webhook confirmed
Channel: Email preferred · SMS fallback
Timing: Immediately on Connect confirmation

**SMS:**
```
Stripe confirmed, {{first_name}}. You're fully operational.

Leads hit your queue at app.solodrive.pro. Ready to book rides.
– SoloDrive
```

**Email:**
```
Subject: Stripe confirmed — you're fully operational

Hey {{first_name}},

Stripe Connect is confirmed. You're fully operational on SoloDrive.

New leads will appear in your queue at https://app.solodrive.pro
when customers book through your page.

— SoloDrive
```

---

## Stack 2 — Runtime (app.solodrive.pro)

Audience: Customers (riders) primary · Operators secondary
Default customer channel: SMS (unless email provided at intake)
Default operator channel: Email (unless SMS preference set)

---

### T2.1 — Quote Ready *(SMS required)*

Trigger: Quote built, occupancy confirmed serviceable, operator approved for presentation
Channel: SMS — overrides preference
Timing: Immediately when quote enters `presented` state

```
Your ride quote is ready.

Review and confirm here:
solodrive.pro/trip/{{token}}

– {{tenant_name}} via SoloDrive
```

---

### T2.2 — Booking Confirmed *(SMS required)*

Trigger: Customer approved quote, auth attempt succeeded
Channel: SMS — overrides preference
Timing: Immediately on auth attempt success

```
Confirmed ✓

{{pickup_short}} → {{dropoff_short}}
{{date}} at {{time}}

Your driver will be ready. Questions? Reply here.
– {{tenant_name}}
```

---

### T2.3 — Ride Complete

Trigger: Ride marked complete, Stripe capture processed
Channel: Email preferred · SMS fallback
Timing: Immediately on capture confirmation

**SMS:**
```
Your ride is complete.

${{amount}} charged to your card on file.

Thanks for riding with {{tenant_name}}. – SoloDrive
```

**Email:**
```
Subject: Your ride with {{tenant_name}} is complete

Hi {{customer_name}},

Your ride is complete.

  Route:   {{pickup_short}} → {{dropoff_short}}
  Amount:  ${{amount}}

Thank you for riding with {{tenant_name}}.

Questions? Reply to this email.

— {{tenant_name}} via SoloDrive
```

---

### T2.4 — New ASAP Lead *(SMS required)*

Trigger: ASAP lead created, occupancy ledger confirms serviceable
Channel: SMS — overrides preference
Recipient: Operator

```
New lead — ASAP

{{pickup_short}} → {{dropoff_short}}

Review: app.solodrive.pro/queue
```

---

### T2.5 — New Reserve Lead

Trigger: Reserve lead created, occupancy projected/confirmed
Channel: Email preferred · SMS fallback
Recipient: Operator

**SMS:**
```
New reservation request

{{date}} at {{time}}
{{pickup_short}} → {{dropoff_short}}

Review: app.solodrive.pro/queue
```

**Email:**
```
Subject: New reservation — {{date}} at {{time}}

New reservation request

  Date/Time:  {{date}} at {{time}}
  Route:      {{pickup_short}} → {{dropoff_short}}

Review and respond:
https://app.solodrive.pro/queue

— SoloDrive
```

---

### T2.6 — Customer Approved — Dispatch *(SMS required)*

Trigger: Auth attempt success (fires alongside T2.2)
Channel: SMS — overrides preference
Recipient: Operator

```
Customer confirmed ✓

{{customer_name}} approved the quote.
{{pickup_short}} → {{dropoff_short}} at {{time}}

Dispatch from your dashboard.
```

---

## Kernel Integration Contract

### Outbound SMS — Quo API

```
POST https://api.quo.com/v1/messages
Authorization: Bearer {{SD_QUO_API_KEY}}

{
  "from": "+12292322442",
  "to": "{{recipient_e164}}",
  "content": "{{rendered_sms_body}}"
}
```

### Outbound Email — Resend API

```
POST https://api.resend.com/emails
Authorization: Bearer {{SD_RESEND_API_KEY}}

{
  "from": "SoloDrive <noreply@solodrive.pro>",
  "to": ["{{recipient_email}}"],
  "subject": "{{rendered_subject}}",
  "text": "{{rendered_plain_body}}"
}
```

HTML email templates are optional at launch. Plain text is acceptable for transactional messages.

### SD_Comms_Service — required methods

```php
SD_Comms_Service::send(string $template_id, array $vars, Contact $contact): void
SD_Comms_Service::send_sms(string $phone_e164, string $template_id, array $vars): void
SD_Comms_Service::send_email(string $email, string $template_id, array $vars): void
SD_Comms_Service::render_sms(string $template_id, array $vars): string
SD_Comms_Service::render_email(string $template_id, array $vars): array // ['subject','body']
SD_Comms_Service::create_or_update_quo_contact(array $data): void
```

### Trigger → Template Map

| Lifecycle event | Template(s) | Recipient |
|---|---|---|
| Prospect form submitted | T1.1 | Prospect |
| Runtime tenant provisioned / runtime_tenant_id writeback | T1.2 | Operator |
| 24hr cron, Connect incomplete | T1.3 | Operator |
| Stripe Connect confirmed | T1.4 | Operator |
| Quote → `presented` state | T2.1 | Customer |
| Auth attempt success | T2.2 + T2.6 | Customer + Operator |
| Ride complete + capture | T2.3 | Customer |
| ASAP lead created, serviceable | T2.4 | Operator |
| Reserve lead created, serviceable | T2.5 | Operator |

### Inbound webhook (Quo → kernel)

Endpoint: `https://app.solodrive.pro/wp-json/solodrive/v1/quo/inbound`

```json
{
  "from": "+1XXXXXXXXXX",
  "to": "+12292322442",
  "body": "message text",
  "direction": "incoming",
  "createdAt": "ISO8601"
}
```

Routing:
1. Look up by `from` in Quo contacts + kernel records
2. Matched `customer` with active lead → log to lead, flag for operator
3. Matched `operator` → log to operator queue note
4. Matched `prospect` → log, flag for follow-up
5. Unmatched → hold message, create unknown contact for review

---

## Configuration Checklists

### Quo UI

- [ ] Settings → API → Generate key → `SD_QUO_API_KEY` in WP
- [ ] Settings → Integrations → Webhooks → add inbound URL
- [ ] Sona → fallback auto-reply for unknown inbound

### Resend

- [ ] Account at resend.com
- [ ] Add domain: `solodrive.pro`
- [ ] Add SPF + DKIM records in Cloudflare (Resend provides exact values)
- [ ] Add DMARC record: `v=DMARC1; p=none; rua=mailto:dmarc@solodrive.pro`
- [ ] Verify domain
- [ ] Generate API key → `SD_RESEND_API_KEY` in WP

### WordPress / SDPRO kernel constants

```php
define('SD_QUO_API_KEY',       '...');
define('SD_QUO_FROM',          '+12292322442');
define('SD_RESEND_API_KEY',    '...');
define('SD_EMAIL_FROM',        'noreply@solodrive.pro');
define('SD_EMAIL_FROM_NAME',   'SoloDrive');
```

### Storefront intake form

- [ ] Add optional `customer_email` field
- [ ] Add conditional `comms_preference` toggle (show when email provided)
- [ ] Persist preference to `sd_comms_pref` on lead record at creation

### Prospect form (solodrive.pro)

- [ ] Add required `contact_email` field
- [ ] Add `comms_preference` toggle (default: Email)
- [ ] Persist preference to `sd_comms_pref` on prospect record

---

## Variable Reference

| Variable | Source | Notes |
|---|---|---|
| `{{first_name}}` | Contact record | Prospect or operator first name |
| `{{slug}}` | runtime tenant/provision package mirror | Tenant storefront slug |
| `{{token}}` | Lead / quote | /trip/ surface token |
| `{{tenant_name}}` | provision package / runtime tenant mirror | Business display name |
| `{{pickup_short}}` | Lead place_id resolved | Short place name, not full address |
| `{{dropoff_short}}` | Lead place_id resolved | Short place name |
| `{{date}}` | Lead `requested_datetime` | Formatted local date |
| `{{time}}` | Lead `requested_datetime` | Formatted local time (tenant tz) |
| `{{amount}}` | Capture record | Dollar-formatted e.g. `$42.00` |
| `{{connect_url}}` | Stripe Connect refresh URL | Generated per operator |
| `{{customer_name}}` | Lead contact name | First name only |
| `{{recipient_email}}` | `sd_comms_pref.email` | Passed to Resend payload |

---

## SMS Segment Reference

| Template | Approx chars | Segments |
|---|---|---|
| T1.1 SMS | ~130 | 1 |
| T1.2 SMS | ~190 | 2 |
| T1.3 SMS | ~175 | 2 |
| T1.4 SMS | ~130 | 1 |
| T2.1 | ~90 | 1 |
| T2.2 | ~130 | 1 |
| T2.3 SMS | ~95 | 1 |
| T2.4 | ~75 | 1 |
| T2.5 SMS | ~90 | 1 |
| T2.6 | ~115 | 1 |

T1.2 and T1.3 SMS hit 2 segments — acceptable since they fire once per operator. All runtime customer-facing SMS are 1 segment.