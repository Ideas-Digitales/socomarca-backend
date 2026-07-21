# Contributing

This document covers local setup pointers and, in detail, the testing conventions used across this codebase. Follow the testing section closely when writing or refactoring tests — it reflects a deliberate migration this project is mid-way through, not just style preference.

## Running tests

Tests run inside the app container. Never run the full suite when validating a change to a handful of files — scope with `--filter`:

```bash
docker compose exec app php artisan test --filter=OrderTest
docker compose exec app php artisan test --filter='OrderTest|CartTest'
```

Only run the full suite when explicitly asked to, or right before a release/PR.

---

## Testing standards

### 1. Authenticate with `Sanctum::actingAs(...)`, not `actingAs()`

The API is moving to Sanctum **token abilities**, enforced by the `abilities:<name>` middleware on top of `auth:sanctum`. Plain `actingAs($user)` / `actingAs($user, 'sanctum')` (session-guard auth) does **not** carry token abilities — Sanctum treats session-authenticated requests as having every ability, and it also fails when the app code touches `currentAccessToken()` (session auth yields a `TransientToken`, which has no `id`, causing fatal errors in code that expects a real token row).

Use the real thing:

```php
use Laravel\Sanctum\Sanctum;

Sanctum::actingAs($user, ['api-access']);
```

**Before picking an ability, check the route's actual middleware** — don't cargo-cult `['api-access']` everywhere:

- Routes inside `Route::middleware('auth:sanctum')->middleware('abilities:api-access')->group(...)` in `routes/api.php` need `['api-access']`.
- A few endpoints require a narrower, purpose-specific ability instead — e.g. `/credentials` requires `['credentials-restore']`, the ability issued specifically by the password-recovery flow. Grep the route definition for `abilities:` before assuming.
- Many routes only apply `permission:<name>` (Spatie) on top of `auth:sanctum`, with **no** `abilities:` middleware at all (most of `Siteinfo`, `Webpay config`, `Firebase config`). For these, the ability array passed to `Sanctum::actingAs()` doesn't gate anything — passing `['api-access']` is harmless and kept only for consistency, not because it's required.
- A handful of routes (e.g. `webpay.return`) are intentionally public and never call `$request->user()`. Don't add `actingAs()` calls there just for consistency — it's dead weight and can mask the fact that the endpoint is unauthenticated by design.

When a test needs a **real** access-token row (not `Sanctum::actingAs()`'s Mockery-backed fake token), e.g. because the controller reads `$request->user()->currentAccessToken()->id`, create one for real and authenticate via header:

```php
$token = $user->createToken('device', ['credentials-restore']);

withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])
    ->patchJson(route('credentials.update'), [...]);
```

### 2. Use native Pest functions, not `$this->`

This project is removing `$this->getJson()`, `$this->postJson()`, `$this->assertDatabaseHas()`, `$this->mock()`, etc. in favor of Pest's functional API:

```php
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\mock;
use function Pest\Laravel\instance;
```

Only reach for `$this->` when there's genuinely no `Pest\Laravel` equivalent (e.g. `$this->assertDatabaseCount()` does have one — check `vendor/pestphp/pest-plugin-laravel/src/*.php` before assuming there isn't one).

### 3. Use `it()`, not `test()`

Convert `test('description', ...)` to `it('description', ...)`. Write descriptions so they read naturally after "it": verb-first, active voice.

```php
// Bad
test('el job maneja errores correctamente', function () { ... });
test('can view their own cart', function () { ... });

// Good
it('handles errors correctly', function () { ... });
it('returns the authenticated user\'s cart', function () { ... });
```

### 4. Test descriptions are English; app-facing strings are not translated

Translate Spanish `it()`/`test()` descriptions to English. **Do not** touch assertion values that check real API/app output (error messages, response `message` fields, mail subjects) even when they're in Spanish — those are the actual contract the test verifies, and "fixing" them just makes the test lie about what the API returns. Example: `'El carrito está vacío'`, `'Contraseña actualizada correctamente'` stay as-is; they're literal strings the controller returns.

### 5. Extract shared setup into `tests/Scenarios/*`

When two or more tests need the same non-trivial fixture graph (a user with specific permissions plus related models), extract it into a `Tests\Scenarios\XxxScenario` class rather than repeating it inline or reaching for a `beforeEach` + `$this->propertyBag` pattern.

Conventions for a Scenario class:

- Lives in `tests/Scenarios/`, namespace `Tests\Scenarios`.
- Public, readonly-by-convention properties for the built fixtures (`public User $user`, `public Product $product`, ...).
- A static `make(...)` factory method that builds and returns an instance. Accept parameters for the things individual tests legitimately need to vary (e.g. `OrderScenario::make()` vs. helper methods like `$scenario->addProductToCart($price, $qty)` for per-test variations).
- If a JSON structure (`assertJsonStructure(...)` payload) is reused across tests, put it on the scenario as a public array property (`$scenario->listJsonStructure`, `$scenario->payJsonStructure`) instead of inlining the array in every test.
- Scenario classes can call the global helper functions declared in `tests/Pest.php` (`createUserWithPermissions()`, `getPriceListCode()`, etc.) — they're in the global namespace and resolve fine from `Tests\Scenarios`.

```php
namespace Tests\Scenarios;

class OrderScenario
{
    public array $listJsonStructure = [ /* ... */ ];

    public function __construct(
        public User $user,
        public Branch $branch,
    ) {}

    public static function make(): OrderScenario
    {
        $user = createUserWithPermissions(['read-own-orders', 'create-orders']);
        $branch = Branch::factory()->create(['user_id' => $user->id]);

        return new OrderScenario($user, $branch);
    }
}
```

Don't build a Scenario class for a single one-off test with a trivial fixture (`User::factory()->create()`) — that's overhead without payoff. Reach for one when setup is either reused or has real shape (multiple related models, a JSON structure, a non-obvious permission set).

### 6. Drop redundant global setup

`tests/Pest.php` already does the following for every test under `tests/Feature`, so don't repeat it per-file:

- `uses(RefreshDatabase::class)->in('Feature')` — no need for a per-file `uses(RefreshDatabase::class);`.
- `Tests\TestCase::$seed = true` with `$seeder = TestingSeeder::class` (which runs `RolesAndPermissionsSeeder` + `PaymentMethodSeeder`) — no need for a per-test `$this->seed(RolesAndPermissionsSeeder::class)`.

If you find these in a file you're touching, remove them.

### 7. When a test fails, figure out *why* before "fixing" it

A failing test after this migration is one of three things — diagnose which before touching anything:

1. **Just the auth mechanism.** `Sanctum::actingAs()` wasn't used, or was called with the wrong ability. Fix per section 1.
2. **The test encodes stale behavior.** The source was intentionally changed (check `git log -p` on the controller/service) and the test wasn't updated to match. In this codebase, several endpoints have been reimplemented (e.g. `CredentialController::update`, `RandomApiService::makeRequest`) without a matching test update — commit messages sometimes say so explicitly ("MISSING FULL TEST SUITE UPDATE!!!"). **Source is the ground truth in this scenario** — rewrite the test to match current behavior, don't change the controller to satisfy an old test.
3. **A real, separate bug.** The test's premise is still correct, but something is legitimately broken (a copy-paste typo, a missing auth call, a dropped fake). Fix the test/source directly, and call it out explicitly rather than silently patching around it.

Cases actually found doing this migration, as a reference for what each looks like:

- **Auth mechanism only**: dozens of routes returning 401/403 once `Sanctum::actingAs()` replaced `actingAs()`.
- **Stale test, source is correct**: `CredentialControllerTest` assumed a `current_password`/`password` contract; the controller had been rewritten to always auto-generate a temporary password and require a different token ability. Test was rewritten from scratch to match.
- **Real bug in the test file itself**: `CartTest` had `Sanctum::actingAs($user ['api-access'])` (missing comma — silently became array-index access) and a test that dropped its `actingAs()` call entirely during a previous edit, running unauthenticated. Both were straight bugs, fixed as such.
- **Real bug in source, worked around in tests for now**: `RandomApiService::createDocument()` still gates on `config('app.env') == 'local'`, while its sibling `makeRequest()` was simplified to always use `config('random.token')`. In the `testing` env this makes `createDocument()` fall through to a real, unfaked `/login` call and fail — which happened to produce the *same visible symptom* (swallowed error, untouched order) as the behavior some tests were actually trying to verify, so they passed for the wrong reason. Fixed in tests by also faking `/login` (matching the pattern already used elsewhere, e.g. `CreditLineTest`), but the source inconsistency itself is still there and worth fixing directly — flag it instead of re-patching it in every new test that touches this path.

### 8. Mocking external services

- **HTTP calls** (Random ERP, etc.): `Http::fake([...])`. Fake **every** endpoint the code path will actually hit, not just the one under test — e.g. if the service falls back to `getToken()` on a 401, fake `/login` too, even if the happy path doesn't need it. An unfaked URL doesn't necessarily fail the test outright; it can silently degrade into a real network call whose failure is caught upstream and produces a *plausible-looking* false pass. Prefer asserting the exact request was sent (`Http::assertSent(fn ($r) => ...)`) over only asserting the end state, when the request shape itself is part of what you're testing.
- **Storage**: `Storage::fake('<disk>')` — match the **actual disk** the code uses (check the controller/service, don't assume `'public'`). If the code calls `->url()` on the faked disk and the test asserts the result looks like a real absolute URL, pass an explicit `url` override: `Storage::fake('s3', ['url' => 'https://fake-bucket.test'])` — otherwise the local fake driver returns a relative `/storage/...` path.
- **Container-bound services** (e.g. `WebpayService`): use `Pest\Laravel\instance()` / `Pest\Laravel\mock()`, not `$this->instance()` / `$this->mock()` (see section 2).
- **Artisan-invoked migrations**: if a test calls `Artisan::call('migrate', ['--path' => ...])` to exercise a specific migration's logic, remember `RefreshDatabase` already ran every migration up front — re-running one by path through the tracked migrator is a no-op (already marked "Ran"). To actually exercise the migration's `up()`/`down()`, `require` the file and call the method on the anonymous class directly.

---

## Quick checklist for a test file migration

- [ ] `Sanctum::actingAs($user, [...])` with the ability the route actually enforces (check `routes/api.php`)
- [ ] No `$this->getJson/postJson/putJson/patchJson/assertDatabaseHas/mock/instance` — use `Pest\Laravel\*` functions
- [ ] `it()`, not `test()`; descriptions read naturally and are in English
- [ ] App-facing string assertions (messages, mail content) left untouched even if in Spanish
- [ ] Shared/non-trivial fixtures extracted into `tests/Scenarios/XxxScenario`
- [ ] No redundant `uses(RefreshDatabase::class)` or `$this->seed(...)`
- [ ] Every failure diagnosed (auth-only / stale test / real bug) before being "fixed"
- [ ] External HTTP/Storage/container dependencies faked completely, matching the real disk/endpoints the code uses
- [ ] Ran the specific file with `--filter` and confirmed green before considering it done
