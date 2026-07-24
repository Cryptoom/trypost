# Post-Subscription Onboarding Design

**Date:** 2026-07-24  
**Branch:** `feature/post-subscription-onboarding`  
**Status:** Approved for planning

## Problem

TryPost’s current “onboarding” is ICP qualification (persona, goals, referral source) plus a mandatory social connect before Stripe. After payment, `/billing/processing` sends the user to `/accounts`. There is no activation moment — no guided path to connect an MCP agent, connect networks in context, or create a first post.

The ICP for TryPost is people who create content via MCP clients (Claude, ChatGPT). Without a dedicated post-subscription activation experience, users miss the AHA moment.

## Goals

1. Rename and clarify **Welcome** (pre-Stripe ICP) vs **Onboarding** (post-Stripe activation).
2. After Stripe, land on a **single-page** activation checklist at `/onboarding`.
3. AHA = MCP connected + first post created (**draft is enough**; publish optional).
4. Flow is **skippable**, with a dismissible residual checklist in the app.
5. Move social connect out of pre-Stripe into post-Stripe onboarding.
6. v1 MCP clients: **Claude** and **ChatGPT** only. First-post step is dual: MCP primary + UI fallback.

## Non-goals (v1)

- Hard-gating the app until onboarding is complete.
- Multi-step wizard for activation (no `/onboarding/step-*`).
- Cursor (or other MCP clients) as first-class cards.
- Requiring publish as the AHA.
- Changing the pre-Stripe ICP questions themselves (only routes/names/structure).

## End-to-end flow

```
/register
  → /register/success
  → /welcome                    (redirect → /welcome/persona)
  → /welcome/persona
  → /welcome/goals
  → /welcome/referral-source
  → /welcome/subscribe          (plan + Stripe CTA; no social required)
  → Stripe Checkout
  → /billing/processing?session_id=…
  → /onboarding                 (single-page checklist)
  → Skip or complete → /calendar
```

Self-hosted: skip Welcome and activation Onboarding (same policy as today’s onboarding skip → calendar).

## URL map

### Welcome (ICP, pre-subscription)

| Method | Path | Name | Purpose |
|--------|------|------|---------|
| GET | `/welcome` | `app.welcome` | Redirect to `app.welcome.persona` |
| GET/POST | `/welcome/persona` | `app.welcome.persona` / `.store` | Persona |
| GET/POST | `/welcome/goals` | `app.welcome.goals` / `.store` | Goals |
| GET/POST | `/welcome/referral-source` | `app.welcome.referral-source` / `.store` | Referral |
| GET | `/welcome/subscribe` | `app.welcome.subscribe` | Plan summary + subscribe CTA |
| POST | `/welcome/checkout` | `app.welcome.checkout` | Start Stripe Checkout |

Legacy redirects (temporary):

- `/onboarding` → `/welcome/persona` (only while unsubscribed / for old bookmarks; after activation ships, subscribed users hitting old paths should not bounce into Welcome)
- `/onboarding/goals` → `/welcome/goals`
- `/onboarding/referral-source` → `/welcome/referral-source`
- `/onboarding/connect` → `/welcome/subscribe`

`EnsureAccountReady`: unsubscribed users → `app.welcome.persona` (not activation `/onboarding`).

### Onboarding (activation, post-subscription)

| Method | Path | Name | Purpose |
|--------|------|------|---------|
| GET | `/onboarding` | `app.onboarding` | Single-page activation checklist |
| POST | `/onboarding/dismiss` | `app.onboarding.dismiss` | Skip / do later |
| POST | `/onboarding/complete` | `app.onboarding.complete` | Explicit complete when all steps done (optional if auto-complete navigates) |

`/billing/processing` success redirect target changes from `/accounts` to `/onboarding`.

## Code rename (Welcome)

| Today | After |
|-------|-------|
| `OnboardingController` | `WelcomeController` |
| `app/Http/Requests/App/Onboarding/*` | `app/Http/Requests/App/Welcome/*` (`StoreWelcomePersonaRequest`, `StoreWelcomeGoalsRequest`, `StoreWelcomeReferralSourceRequest`) |
| `resources/js/pages/onboarding/{Index,Goals,ReferralSource,Connect}.vue` | `resources/js/pages/welcome/{Persona,Goals,ReferralSource,Subscribe}.vue` |
| `OnboardingLayout.vue` | `WelcomeLayout.vue` |
| `lang/*/onboarding.php` (ICP strings) | `lang/*/welcome.php` |
| `tests/Feature/Onboarding/OnboardingControllerTest.php` | `tests/Feature/Welcome/WelcomeControllerTest.php` |
| `app.onboarding.*` (ICP) | `app.welcome.*` |

`Connect.vue` is removed from Welcome. Social connect lives on the activation page (and remains available at `/accounts`).

Checkout no longer requires an existing social account.

## Onboarding page (single page, not a step form)

One dedicated full-page screen with a **3-item checklist** on the same view:

1. **Connect an agent** — Claude and ChatGPT cards with MCP URL / deep-link / copy instructions (link to docs where needed).
2. **Connect a social network** — reuse existing connect UI patterns (platforms + connected accounts), inlined on this page.
3. **Create your first post** — primary: ready-made MCP prompt (“open Claude/ChatGPT and ask…”); fallback: button to create in TryPost UI. Draft counts.

### Live completion

Steps auto-check from real state (poll or Inertia partial reload):

| Step | Done when |
|------|-----------|
| MCP | User has a non-revoked OAuth access token used for MCP (not a Personal Access Token / API key) |
| Social | Current workspace has ≥ 1 social account |
| First post | Current workspace has ≥ 1 post (any status, any `created_via`) |

Primary CTA: continue into the app when all three are done (or celebrate in place then go to calendar).  
Secondary: **Skip / I’ll do this later**.

### Gating policy

- **Not blocking.** After the post-checkout land, users can skip and use the product.
- No middleware that traps subscribed users on `/onboarding` on every request.
- Residual checklist in the app shell until completed or dismissed.

## Persistence

Welcome ICP fields stay on `User`: `persona`, `goals`, `referral_source`.

Activation flags on `Account` (billing/subscription owner — preferred over User so workspace members don’t each own account-level activation):

- `onboarding_completed_at` (nullable timestamp)
- `onboarding_dismissed_at` (nullable timestamp)

Semantics:

- Steps themselves are derived from state, not stored booleans.
- `onboarding_completed_at` set when all three steps are true (server-side on dismiss-complete path or when status is loaded and all true).
- `onboarding_dismissed_at` set on Skip.
- Residual UI shows when account is subscribed and both timestamps are null.
- Dismiss hides residual even if steps remain incomplete.
- Completing all three sets `completed_at` and clears residual.

Existing accounts already subscribed: treat as dismissed or completed (migration strategy: backfill `onboarding_dismissed_at = now()` for accounts that already have an active subscription, so we don’t force legacy users into activation).

## Residual checklist

Dismissible banner or compact checklist in the authenticated app layout (not a modal trap):

- Visible when subscribed + `completed_at` null + `dismissed_at` null.
- Link back to `/onboarding`.
- Dismiss action hits `app.onboarding.dismiss`.

Exact UI placement (sidebar vs top banner) is an implementation detail; prefer the lightest pattern that matches existing app chrome.

## Analytics (PostHog)

Follow existing enum pattern under `App\Enums\PostHog\` (e.g. `WelcomeEvent`, `OnboardingEvent`).

Suggested events:

- Welcome: `welcome.persona_saved`, `welcome.goals_saved`, `welcome.referral_saved`, `welcome.checkout_started`
- Onboarding: `onboarding.viewed`, `onboarding.step_completed` (property `step`: `mcp` | `social` | `first_post`), `onboarding.skipped`, `onboarding.completed`

Step properties when useful: `created_via` (`web` | `mcp`) for first post; MCP client if detectable later (v1 may omit client if unreliable).

## Architecture notes

- **WelcomeController** — ICP + subscribe + checkout only; redirects subscribed users to `/onboarding` or calendar per rules (subscribed + activation incomplete → can visit `/onboarding`; subscribed + complete/dismissed → calendar).
- **OnboardingController** — show checklist props (step statuses, platforms, accounts, MCP server URL, sample prompt), dismiss, complete.
- Prefer a small action/service e.g. `App\Actions\Onboarding\ResolveOnboardingStatus` that returns the three booleans + whether residual should show — shared by page, layout shared props, and tests.
- Frontend: one `resources/js/pages/onboarding/Index.vue`; poll step status while the page is open so MCP/OAuth and MCP-created drafts flip checkmarks without a full navigation.
- Wayfinder regenerate after route/controller rename.
- i18n: all new copy in `lang/*/welcome.php` and `lang/*/onboarding.php` (all locales that already have onboarding strings).

## Testing

- Welcome: renamed feature tests; persona → goals → referral → subscribe; checkout **without** social account succeeds; subscribed users don’t see Welcome ICP.
- Legacy route redirects.
- Billing processing redirects to `/onboarding`.
- Onboarding page renders step statuses from fixtures (MCP token, social, post).
- Dismiss sets `onboarding_dismissed_at`; residual hidden.
- Auto-complete when all three true sets `onboarding_completed_at`.
- `EnsureAccountReady` → `/welcome/persona` when unsubscribed.
- Self-hosted skips both flows.
- Residual shared prop / layout behavior covered at least once.

## Open implementation details (resolved in plan, not product)

- Exact MCP “connected” query against Passport tokens/clients.
- Claude/ChatGPT deep-link vs copy-paste URL UX copy.
- Residual component placement in `AppLayout`.
- Whether `onboarding.complete` is a dedicated POST or automatic on status resolve.

## Success criteria

- New paid users land on `/onboarding` after Stripe, not `/accounts`.
- They can connect Claude or ChatGPT, connect a network, and create a draft (via MCP or UI) on one page.
- They can skip and still use the product, with an optional residual nudge.
- Pre-Stripe flow lives under `/welcome/{step}` with aligned PHP/Vue naming.
- ICP collection no longer requires social connect before payment.
