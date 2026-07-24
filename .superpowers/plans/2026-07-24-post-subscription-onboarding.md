# Post-Subscription Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename pre-Stripe ICP to Welcome (`/welcome/{step}`), remove social-before-pay, and ship a single-page post-Stripe activation checklist at `/onboarding` (MCP + social + first post) with skip + residual nudge.

**Architecture:** `WelcomeController` owns ICP + Stripe checkout (no social gate). `OnboardingController` owns the activation page behind `EnsureAccountReady`. `ResolveOnboardingStatus` derives MCP/social/post completion and residual visibility; Account stores only `onboarding_completed_at` / `onboarding_dismissed_at`. Frontend: one `onboarding/Index.vue` with Inertia `usePoll`; residual banner via shared Inertia prop in `AppSidebarLayout`.

**Tech Stack:** Laravel 13, Inertia v3 + Vue 3, Cashier, Passport (`AccessToken`), Pest 4, Wayfinder, PostHog enums/jobs, Tailwind v4, `@tabler/icons-vue`.

**Spec:** `.superpowers/specs/2026-07-24-post-subscription-onboarding-design.md`

## Global Constraints

- Activation is one page only — no `/onboarding/step-*` wizard.
- AHA = MCP connected + first post exists (draft OK); publish optional.
- Skippable; no hard-gate after first land.
- v1 MCP clients: Claude + ChatGPT only.
- First post step: MCP prompt primary + UI fallback to `route('app.posts.create')`.
- Social connect removed from pre-Stripe; lives on activation page via existing `NetworkConnectGrid`.
- MCP “connected” = user has non-revoked `AccessToken` with `workspace_id IS NULL` (OAuth/MCP session; PATs bind `workspace_id`).
- Self-hosted skips Welcome and activation (redirect calendar), same as today.
- Never hardcode URLs in tests — use `route()`.
- FormRequests under `app/Http/Requests/App/<Group>/`; no inline `$request->validate`.
- Arrow functions only in Vue/TS; Tabler icons only; Wayfinder for frontend routes.
- After PHP changes: `vendor/bin/pint --dirty --format agent`.
- After route/controller changes: `php artisan wayfinder:generate`.
- i18n: all 15 locales that have `lang/*/onboarding.php`.
- Branch: `feature/post-subscription-onboarding` (already created).
- Do not push or open PR unless asked.

## File structure (target)

| Path | Responsibility |
|------|----------------|
| `app/Actions/Onboarding/ResolveOnboardingStatus.php` | Derive step booleans + residual + maybe mark completed |
| `app/Http/Controllers/App/WelcomeController.php` | ICP + subscribe + checkout |
| `app/Http/Controllers/App/OnboardingController.php` | Activation show / dismiss / complete |
| `app/Http/Requests/App/Welcome/*` | Persona / goals / referral FormRequests |
| `app/Enums/PostHog/WelcomeEvent.php` | Welcome analytics event names |
| `app/Enums/PostHog/OnboardingEvent.php` | Onboarding analytics event names |
| `resources/js/pages/welcome/{Persona,Goals,ReferralSource,Subscribe}.vue` | ICP UI |
| `resources/js/pages/onboarding/Index.vue` | Single activation checklist |
| `resources/js/layouts/WelcomeLayout.vue` | Renamed from OnboardingLayout |
| `resources/js/components/onboarding/OnboardingResidualBanner.vue` | Residual nudge |
| `lang/*/welcome.php` | ICP copy (moved from onboarding) |
| `lang/*/onboarding.php` | Activation copy (replaced) |
| `tests/Feature/Welcome/WelcomeControllerTest.php` | Renamed + updated ICP tests |
| `tests/Feature/Onboarding/OnboardingControllerTest.php` | New activation tests |
| `tests/Feature/Actions/Onboarding/ResolveOnboardingStatusTest.php` | Status derivation |

---

### Task 1: Account onboarding timestamps

**Files:**
- Create: migration via `php artisan make:migration add_onboarding_timestamps_to_accounts_table --no-interaction`
- Modify: `app/Models/Account.php` (`$fillable`, `$casts`)
- Modify: `database/factories/AccountFactory.php` if needed for defaults
- Test: `tests/Feature/Onboarding/AccountOnboardingTimestampsTest.php`

**Interfaces:**
- Produces: `Account::$onboarding_completed_at`, `Account::$onboarding_dismissed_at` (`?CarbonInterface`)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;

test('account can store onboarding completed and dismissed timestamps', function () {
    $user = User::factory()->create();
    $account = $user->account;

    $account->update([
        'onboarding_completed_at' => now(),
        'onboarding_dismissed_at' => now()->subMinute(),
    ]);

    $account->refresh();

    expect($account->onboarding_completed_at)->not->toBeNull()
        ->and($account->onboarding_dismissed_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Onboarding/AccountOnboardingTimestampsTest.php`  
Expected: FAIL (unknown columns / not fillable)

- [ ] **Step 3: Create migration + update Account**

Migration `up`:

```php
Schema::table('accounts', function (Blueprint $table) {
    $table->timestamp('onboarding_completed_at')->nullable()->after('trial_ends_at');
    $table->timestamp('onboarding_dismissed_at')->nullable()->after('onboarding_completed_at');
});

// Backfill: existing subscribed accounts must not see residual/activation.
Account::query()
    ->whereHas('subscriptions', function ($query) {
        $query->where('type', Account::SUBSCRIPTION_NAME)
            ->whereIn('stripe_status', ['active', 'trialing']);
    })
    ->whereNull('onboarding_dismissed_at')
    ->whereNull('onboarding_completed_at')
    ->update(['onboarding_dismissed_at' => now()]);
```

On `Account`: add both fields to `$fillable` and cast to `datetime`.

- [ ] **Step 4: Migrate and run test**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Onboarding/AccountOnboardingTimestampsTest.php`  
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*onboarding_timestamps* app/Models/Account.php tests/Feature/Onboarding/AccountOnboardingTimestampsTest.php
git commit -m "Add account onboarding completed and dismissed timestamps."
```

---

### Task 2: ResolveOnboardingStatus action

**Files:**
- Create: `app/Actions/Onboarding/ResolveOnboardingStatus.php`
- Create: `tests/Feature/Actions/Onboarding/ResolveOnboardingStatusTest.php`

**Interfaces:**
- Consumes: `User`, `Account`, `AccessToken` (workspace_id null = MCP), workspace socialAccounts, workspace posts
- Produces:

```php
/**
 * @return array{
 *     mcp_connected: bool,
 *     social_connected: bool,
 *     first_post_created: bool,
 *     all_complete: bool,
 *     show_residual: bool,
 *     completed_at: ?string,
 *     dismissed_at: ?string
 * }
 */
public function handle(User $user): array
```

When `all_complete` and `onboarding_completed_at` is null, set `onboarding_completed_at = now()` inside this action (idempotent).

`show_residual` = not self-hosted AND account subscribed AND `completed_at` null AND `dismissed_at` null.

MCP connected:

```php
AccessToken::query()
    ->where('user_id', $user->id)
    ->whereNull('workspace_id')
    ->where('revoked', false)
    ->exists();
```

- [ ] **Step 1: Write failing tests** covering: empty state; MCP-only (createToken + forceFill workspace_id null); social-only; post-only; all three auto-sets completed_at; dismissed hides residual; self-hosted show_residual false.

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --compact tests/Feature/Actions/Onboarding/ResolveOnboardingStatusTest.php`

- [ ] **Step 3: Implement `ResolveOnboardingStatus`**

- [ ] **Step 4: Run tests — expect PASS**

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Onboarding tests/Feature/Actions/Onboarding
git commit -m "Add ResolveOnboardingStatus for activation checklist state."
```

---

### Task 3: Rename ICP backend to Welcome

**Files:**
- Create: `app/Http/Controllers/App/WelcomeController.php` (from `OnboardingController`, minus social-required checkout)
- Create: `app/Http/Requests/App/Welcome/StoreWelcomePersonaRequest.php` (ex-StoreOnboardingRequest)
- Create: `app/Http/Requests/App/Welcome/StoreWelcomeGoalsRequest.php`
- Create: `app/Http/Requests/App/Welcome/StoreWelcomeReferralSourceRequest.php`
- Modify: `routes/app.php` — welcome routes; legacy redirects for goals/referral/connect; point `EnsureAccountReady` target
- Modify: `app/Http/Middleware/App/EnsureAccountReady.php` → `route('app.welcome.persona')`
- Delete: old `OnboardingController` ICP methods later (after Task 7 owns new controller) — for this task, rename file to WelcomeController and leave `/onboarding` route temporarily redirecting unsubscribed users via middleware only
- Update all PHP references: `BillingController::subscribe`, tests `EnsureAccountReadyTest`, `WorkspaceBillingTest`, `BillingControllerTest`, `TrialMiddlewareAccessTest`, `SocialControllerTest` onboarding mentions

**Route map to register (auth group, not EnsureAccountReady):**

```php
Route::get('welcome', fn () => redirect()->route('app.welcome.persona'))->name('app.welcome');
Route::get('welcome/persona', [WelcomeController::class, 'persona'])->name('app.welcome.persona');
Route::post('welcome/persona', [WelcomeController::class, 'storePersona'])->name('app.welcome.persona.store');
Route::get('welcome/goals', [WelcomeController::class, 'goals'])->name('app.welcome.goals');
Route::post('welcome/goals', [WelcomeController::class, 'storeGoals'])->name('app.welcome.goals.store');
Route::get('welcome/referral-source', [WelcomeController::class, 'referralSource'])->name('app.welcome.referral-source');
Route::post('welcome/referral-source', [WelcomeController::class, 'storeReferralSource'])->name('app.welcome.referral-source.store');
Route::get('welcome/subscribe', [WelcomeController::class, 'subscribe'])->name('app.welcome.subscribe');
Route::post('welcome/checkout', [WelcomeController::class, 'checkout'])->name('app.welcome.checkout');

// Legacy ICP URLs
Route::redirect('onboarding/goals', '/welcome/goals');
Route::redirect('onboarding/referral-source', '/welcome/referral-source');
Route::redirect('onboarding/connect', '/welcome/subscribe');
```

Do **not** redirect `GET onboarding` — that path becomes activation in Task 7 (behind `EnsureAccountReady`).

**WelcomeController behavior changes vs today:**
- Inertia pages: `welcome/Persona`, `welcome/Goals`, `welcome/ReferralSource`, `welcome/Subscribe`
- After referral store → `app.welcome.subscribe` (not connect)
- `subscribe()`: plan props only — **no** platforms/accounts required
- `checkout()`: remove social-account existence check; `cancel_url` = `route('app.welcome.subscribe')`
- Subscribed users hitting Welcome → redirect `app.onboarding` if residual should show, else `app.calendar` (until OnboardingController exists, temporary redirect to calendar is OK then tighten in Task 7)

- [ ] **Step 1: Move/adapt tests** to `tests/Feature/Welcome/WelcomeControllerTest.php` with new route names; add test that checkout works **without** social account; remove must_connect assertions.

- [ ] **Step 2: Run Welcome tests — expect FAIL** (routes missing)

- [ ] **Step 3: Implement WelcomeController + requests + routes + middleware redirect**

- [ ] **Step 4: Run Welcome + middleware tests — expect PASS**

Run: `php artisan test --compact tests/Feature/Welcome tests/Feature/Middleware/EnsureAccountReadyTest.php tests/Feature/BillingControllerTest.php tests/Feature/WorkspaceBillingTest.php`

- [ ] **Step 5: Commit**

```bash
git commit -m "Rename pre-subscription ICP flow to Welcome routes."
```

---

### Task 4: Welcome frontend + i18n

**Files:**
- Create: `resources/js/layouts/WelcomeLayout.vue` (copy/rename `OnboardingLayout.vue`)
- Create: `resources/js/pages/welcome/Persona.vue`, `Goals.vue`, `ReferralSource.vue`, `Subscribe.vue`
- Create: `lang/*/welcome.php` — move ICP keys from `onboarding.php` (title, personas, goals_*, referral_*, continue; subscribe title/description from old connect minus must_connect)
- Replace: `lang/*/onboarding.php` can stay temporarily until Task 8; or clear ICP keys now
- Delete: `resources/js/pages/onboarding/{Index,Goals,ReferralSource,Connect}.vue`, `OnboardingLayout.vue` after welcome pages work
- Update Vue imports to `@/routes/app/welcome/...` after wayfinder generate

**Subscribe.vue:** plan CTA only — no `NetworkConnectGrid`, no `hasConnected` gate. On submit: `trackBeginCheckout` then POST `app.welcome.checkout`.

- [ ] **Step 1: Move lang files** — for each of 15 locales, create `welcome.php` from ICP portion of `onboarding.php`; add `subscribe.title` / `subscribe.description` (reuse former connect copy without must_connect).

- [ ] **Step 2: Create Vue pages** mirroring old onboarding pages with new routes/i18n keys/`WelcomeLayout`.

- [ ] **Step 3: `php artisan wayfinder:generate`**

- [ ] **Step 4: Smoke** — `php artisan test --compact tests/Feature/Welcome` still PASS; fix Inertia component names in asserts (`welcome/Persona`, etc.).

- [ ] **Step 5: Commit**

```bash
git commit -m "Add Welcome Vue pages and i18n; drop social from subscribe."
```

---

### Task 5: Onboarding activation backend

**Files:**
- Create: `app/Http/Controllers/App/OnboardingController.php`
- Create: `app/Enums/PostHog/OnboardingEvent.php`
- Modify: `routes/app.php` — inside `EnsureAccountReady` + `EnsureHasWorkspace` group:

```php
Route::get('onboarding', [OnboardingController::class, 'index'])->name('app.onboarding');
Route::post('onboarding/dismiss', [OnboardingController::class, 'dismiss'])->name('app.onboarding.dismiss');
Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('app.onboarding.complete');
```

- Create: `tests/Feature/Onboarding/OnboardingControllerTest.php` (activation — do not reuse old ICP file name confusion; old ICP file already moved to Welcome)

**`index` props:**

```php
$status = app(ResolveOnboardingStatus::class)->handle($request->user());

return Inertia::render('onboarding/Index', [
    'status' => $status,
    'mcpUrl' => url('/mcp/trypost'),
    'mcpClients' => [
        ['id' => 'claude', 'label' => 'Claude'],
        ['id' => 'chatgpt', 'label' => 'ChatGPT'],
    ],
    'samplePrompt' => __('onboarding.first_post.sample_prompt'),
    'platforms' => /* same map as old connect */,
    'accounts' => SocialAccountResource::collection($workspace->socialAccounts()->orderBy('id')->get())->resolve(),
    'createPostUrl' => route('app.posts.create'),
]);
```

On first view, capture `OnboardingEvent::Viewed` via `PostHogService` (once per request is fine).

`dismiss`: set `onboarding_dismissed_at`, capture skipped, redirect `app.calendar`.

`complete`: if not `all_complete`, redirect back to onboarding; else ensure `onboarding_completed_at`, capture completed, redirect `app.calendar`.

Self-hosted: index/dismiss/complete → calendar.

Tighten `WelcomeController`: subscribed users → `app.onboarding` when `show_residual`, else calendar.

- [ ] **Step 1: Write activation feature tests** (subscribed + workspace): renders statuses; dismiss sets timestamp; complete requires all steps; unsubscribed redirected by middleware to welcome; self-hosted → calendar.

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement controller + enum + routes**

- [ ] **Step 4: Run activation + Welcome subscribed-redirect tests — PASS**

- [ ] **Step 5: Commit**

```bash
git commit -m "Add post-subscription onboarding activation endpoints."
```

---

### Task 6: Onboarding activation UI (single page)

**Files:**
- Create: `resources/js/pages/onboarding/Index.vue`
- Replace: `lang/*/onboarding.php` with activation copy only (steps, skip, residual, sample prompt, MCP instructions)
- Keep using `NetworkConnectGrid` for social section

**UI structure (one page):**
1. Header + Skip button (`form.post` dismiss)
2. Checklist item MCP — copy `mcpUrl`, Claude/ChatGPT short instructions, checkmark from `status.mcp_connected`
3. Checklist item Social — `NetworkConnectGrid` with props
4. Checklist item First post — show `samplePrompt` + copy button; link/button to `createPostUrl`; checkmark from `status.first_post_created`
5. When `status.all_complete` — primary “Continue to TryPost” → POST complete (or `router.visit` calendar after complete)

**Polling:**

```ts
import { usePoll } from '@inertiajs/vue3';

usePoll(2000, {
  only: ['status', 'accounts'],
});
```

- [ ] **Step 1: Write `lang/en/onboarding.php` activation strings**, then copy keys to other 14 locales (English fallback OK if translation lagging, but keys must exist in all locales that previously had the file).

- [ ] **Step 2: Build `Index.vue`** (arrow functions, Tabler icons, Wayfinder routes for dismiss/complete).

- [ ] **Step 3: `php artisan wayfinder:generate`**

- [ ] **Step 4: Feature test assertInertia component `onboarding/Index` + status keys**

- [ ] **Step 5: Commit**

```bash
git commit -m "Add single-page post-subscription onboarding UI."
```

---

### Task 7: Redirect billing processing → onboarding

**Files:**
- Modify: `resources/js/pages/billing/Processing.vue` — replace `accounts.url()` with onboarding route
- Modify/add tests if any assert post-checkout destination; add feature coverage if missing

```ts
import { index as onboarding } from '@/routes/app/onboarding'; // or named export from wayfinder
const goToOnboarding = () => router.visit(onboarding.url());
// use goToOnboarding in setTimeout instead of goToAccounts
```

- [ ] **Step 1: Change Processing.vue redirect target**

- [ ] **Step 2: Manually verify no remaining `accounts` import used for redirect**

- [ ] **Step 3: Commit**

```bash
git commit -m "Send post-checkout users to onboarding instead of accounts."
```

---

### Task 8: Residual banner + shared prop

**Files:**
- Modify: `app/Http/Middleware/App/HandleInertiaRequests.php` — share lazy `onboardingResidual`:

```php
'onboardingResidual' => fn () => $user && $account
    ? data_get(app(ResolveOnboardingStatus::class)->handle($user), 'show_residual', false)
    : false,
```

- Create: `resources/js/components/onboarding/OnboardingResidualBanner.vue`
- Modify: `resources/js/layouts/app/AppSidebarLayout.vue` — render banner above `<slot />` when `page.props.onboardingResidual`
- Create: `tests/Feature/Onboarding/OnboardingResidualShareTest.php` — hit a subscribed app route (e.g. calendar) and assert shared prop true/false

Banner: short copy + Link to `/onboarding` + dismiss button posting `app.onboarding.dismiss` (or small form).

Skip rendering on the onboarding page itself (`usePage().url` includes `/onboarding`).

- [ ] **Step 1: Write share/residual tests**

- [ ] **Step 2: Implement share + banner**

- [ ] **Step 3: Run tests — PASS**

- [ ] **Step 4: Commit**

```bash
git commit -m "Add dismissible residual onboarding banner in app shell."
```

---

### Task 9: Welcome + Onboarding PostHog events

**Files:**
- Create: `app/Enums/PostHog/WelcomeEvent.php`

```php
enum WelcomeEvent: string
{
    case PersonaSaved = 'welcome.persona_saved';
    case GoalsSaved = 'welcome.goals_saved';
    case ReferralSaved = 'welcome.referral_saved';
    case CheckoutStarted = 'welcome.checkout_started';
}
```

- Create: `app/Enums/PostHog/OnboardingEvent.php`

```php
enum OnboardingEvent: string
{
    case Viewed = 'onboarding.viewed';
    case StepCompleted = 'onboarding.step_completed';
    case Skipped = 'onboarding.skipped';
    case Completed = 'onboarding.completed';
}
```

- Modify: `WelcomeController` — `capture` on store/checkout (keep existing `identify` for persona/goals/referral)
- Modify: `OnboardingController` — viewed / skipped / completed
- Optional: in `ResolveOnboardingStatus` or controller poll path, when a step flips true, capture `StepCompleted` with `step` property — avoid duplicate spam by only capturing when transitioning (session flash keys `onboarding_step_mcp` etc. OR compare previous request; v1 acceptable: capture from frontend once via optional endpoint — prefer server session flags on the Account JSON column only if already needed; simplest v1: capture StepCompleted inside ResolveOnboardingStatus only when the step is true AND a cache key `onboarding_step:{accountId}:{step}` missing, TTL 30 days)

**YAGNI for v1:** capture Viewed, Skipped, Completed, and Welcome saves/checkout; defer per-step transition events if costly — but spec lists them, so implement cache-gated StepCompleted in `ResolveOnboardingStatus` when a step is true.

- [ ] **Step 1: Tests with `Bus::fake` + `SendEvent` asserts** on welcome store and onboarding dismiss/complete/view

- [ ] **Step 2: Implement enums + captures**

- [ ] **Step 3: Run related tests — PASS**

- [ ] **Step 4: Commit**

```bash
git commit -m "Track welcome and onboarding activation events in PostHog."
```

---

### Task 10: Cleanup, Wayfinder, full regression

**Files:**
- Remove dead old onboarding ICP code/files if any remain
- Ensure `Connect.vue` deleted
- Grep for `app.onboarding.goals`, `onboarding/Index` (persona), `OnboardingLayout`, `StoreOnboardingRequest`, `pages/onboarding/Goals`
- Run pint + wayfinder

- [ ] **Step 1: Grep cleanup**

```bash
rg -n "app\.onboarding\.(store|goals|referral|connect|checkout)|OnboardingLayout|StoreOnboarding|pages/onboarding/Goals|onboarding/Connect" --glob '!vendor/**' --glob '!node_modules/**'
```

Expected: no stale ICP references (activation `app.onboarding` / `app.onboarding.dismiss` OK).

- [ ] **Step 2: Pint + wayfinder**

```bash
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
```

- [ ] **Step 3: Run full related suite**

```bash
php artisan test --compact \
  tests/Feature/Welcome \
  tests/Feature/Onboarding \
  tests/Feature/Actions/Onboarding \
  tests/Feature/Middleware/EnsureAccountReadyTest.php \
  tests/Feature/BillingControllerTest.php \
  tests/Feature/WorkspaceBillingTest.php \
  tests/Feature/Social/SocialControllerTest.php
```

Fix failures.

- [ ] **Step 4: Final commit if needed**

```bash
git commit -m "Clean up legacy onboarding ICP references after Welcome rename."
```

---

## Self-review (spec coverage)

| Spec requirement | Task |
|------------------|------|
| Welcome `/welcome/{step}` URLs | 3–4 |
| Remove social pre-pay | 3–4 |
| Checkout without social | 3 |
| `/onboarding` single page | 5–6 |
| MCP + social + first post checklist | 5–6 |
| Draft counts / dual create path | 6 (`createPostUrl` + prompt) |
| Skippable + residual | 5, 8 |
| Account timestamps + backfill | 1 |
| ResolveOnboardingStatus | 2 |
| Processing → onboarding | 7 |
| EnsureAccountReady → welcome | 3 |
| PostHog events | 9 |
| Self-hosted skip | 3, 5 |
| Legacy redirects (except GET `/onboarding`) | 3 |
| i18n all locales | 4, 6 |

**Resolved open details:**
- MCP detection = `AccessToken` with `workspace_id` null, not revoked.
- Complete = dedicated POST `app.onboarding.complete` + auto-set timestamp in ResolveOnboardingStatus.
- Residual = shared `onboardingResidual` + banner in `AppSidebarLayout`.
- MCP UX v1 = copy `mcpUrl` + Claude/ChatGPT labels (no fragile deep links required).
