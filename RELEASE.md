> v0.0.8 ~ "Solid-OIDC brought in-house, and a verified authorization callback"

---
## Highlights

Solid can be installed alongside current Core API again. `jumbojett/openid-connect-php` pinned `phpseclib/phpseclib ^3.0.7`, and Core API's `laravel/socialite ^5.31` requires `^4.0`, so the two could not coexist — Composer refused to resolve the application at all. Upstream Jumbojett has not migrated to phpseclib 4, and phpseclib 4 renamed its root namespace, so widening the constraint would resolve and then fail at runtime.

The dependency turned out to be carrying that pin for code Solid never ran. Jumbojett touches phpseclib in one method, RSA signature verification, which this extension already overrode — and the authorization callback never reached Jumbojett's verification at all. Roughly 150 lines of a 2,100-line library were in use, with transport, token requests, client registration, session storage and discovery all replaced locally.

So the library is gone and the Solid-OIDC client lives in the extension, in `server/src/Client/OpenIDConnectClient.php` and `server/src/Auth/`. Solid-OIDC needs three things no general-purpose OAuth client provides — RFC 7591 dynamic client registration, RFC 9449 DPoP-bound tokens, and an issuer discovered per tenant at runtime — and all three were already written here. ID token verification follows the conventions of Core API's `Fleetbase\Auth\OAuth\IdTokenVerifier`.

Closing that out fixed a set of real gaps in the handshake, described below. **No user has to sign in again:** existing client registrations are reused and existing DPoP keys are migrated rather than regenerated.

---
## Security

The authorization callback now verifies what it receives. Previously it exchanged whatever code it was handed, and the WebID that drives every pod read and write was taken from an unverified JWT.

- **ID token verification.** Signature checked against the provider's published JWKS, plus `iss` (exact), `aud`, `azp` when `aud` is multi-valued, expiry with a bounded 60-second leeway, `nonce`, and the presence of `sub`. None of these were checked before.
- **`state` validation.** Server-side, single use, consumed before comparison so a rejected callback cannot be retried, and a mismatch now voids the whole pending request. `state` was previously ignored entirely.
- **DPoP private keys are no longer web-reachable.** They were written with a bare `Storage::put()`, and the application's default disk is `public` — rooted at `storage/app/public`, symlinked to `public/storage` and served over HTTP — or an S3/GCS bucket. Keys now go to an explicitly named private disk (`solid.oidc.key_disk`, default `local`), and a key found in the old location is moved there and the exposed copy deleted.
- **Algorithm confusion.** A key without an `alg` is dropped rather than assumed to be RS256, and a token whose header algorithm differs from its key's is rejected, so `alg: none` and HS256-signed-with-the-RSA-public-key both fail.
- **PKCE** is mandatory and fails loudly if the provider advertises methods that exclude S256, instead of silently dropping the challenge.
- **TLS verification** is one explicit flag, `solid.oidc.verify_tls`, rather than inferred from `APP_ENV` — which also disabled it in any environment an operator happened to name `local` or `development`. Local development against a self-signed certificate is unchanged by default; set `SOLID_OIDC_VERIFY_TLS=false` to say so explicitly.
- **Redirects are no longer followed** on OIDC requests. The old transport set `CURLOPT_FOLLOWLOCATION`, which could replay an `Authorization: Basic` client secret against whatever host a `Location` header named.
- **Tokens are out of the logs.** The Solid client was logging the decoded access-token payload and then the whole header set, including `Authorization: DPoP <token>` and the DPoP proof.
- **`state`, `nonce` and the PKCE verifier now expire.** They were written to Redis with no TTL, so an abandoned sign-in left them readable indefinitely.
- The access token's `cnf.jkt` is checked against the DPoP key held for the identity, so a token bound to a different key fails at sign-in rather than on every subsequent request.

---
## Fixes

- DPoP `htu` strips the query and fragment, as RFC 9449 §4.2 requires. A proof built for a URL with parameters was unusable against a server that compares `htu` strictly.
- RFC 9449 §8 `use_dpop_nonce` is honoured: the token request is retried once with the nonce the provider asks for.
- Provider discovery is cached, per process and in the application cache. Every Solid request built a fresh client and refetched `.well-known/openid-configuration`, so a single controller action touching four resources made four extra round trips.
- The authorization callback reports a provider `error` instead of turning it into "missing authorization code".
- `authenticate()` returns a redirect response rather than calling `header()` and `exit`, so the redirect goes through the framework.
- Dynamic client registration declares `grant_types` including `refresh_token`. Without it a provider defaults to `authorization_code` alone, which made the requested `offline_access` scope unusable. Existing registrations are untouched; this applies to new ones.
- Requested scopes are deduplicated — the authorization request was sending `openid webid offline_access openid`.
- `firebase/php-jwt` is declared. It was already in use but relied on arriving transitively.

---
## Dependencies

- Removed: `jumbojett/openid-connect-php`, all seven `web-token/jwt-*` packages, `php-http/guzzle7-adapter`, `psr/http-factory-implementation`. The `web-token` stack and the PSR adapters it needed had no references anywhere in the extension.
- Added: `firebase/php-jwt ^6.10|^7.0` (shared with Socialite rather than duplicating a JWT stack), `ext-json`, `ext-openssl`.
- `php` raised from `^8.0` to `^8.1`, matching Core API and phpseclib 4.
- Engine dependencies moved to their latest releases: `@fleetbase/ember-core` `^0.3.24`, `@fleetbase/ember-ui` `^0.4.3`, `@fleetbase/fleetops-data` `^0.2.2`. CI builds on Node 22.

---
## Also fixed

Four defects that predate this release, found while auditing the OIDC code:

- **`PodService` resolved a class that does not exist.** The commit that removed the CSS-credential approach in favour of OIDC tokens deleted `CssAccountService` but left two `app(CssAccountService::class)` call sites behind, so pod creation and the pod listing threw on entry. Those branches are removed, finishing that change.
- **`getAccountIndex()` ended in `dd()`** on the live `GET solid/int/v1/account` route, halting the request and dumping the raw pod response. It returns JSON now. Seven more routes pointed at controller methods that do not exist (`play`, `getProfileData` and the five `sync-*` actions) and would 500 on hit; nothing calls them, so they are removed. Every routed method now resolves.
- **The admin server-configuration UI had no effect.** `saveServerConfig()` wrote `system.solid.server` and `SolidClient` never read it, so changing the Solid host or port in the console changed nothing. Resolution is now explicit option → saved setting → config default. Two bugs in the same lines went with it: a caller-supplied host was silently discarded, and `new SolidClient([])` raised a `TypeError`.
- **`composer test` failed in all three stages.** It passes now: `test:unit` is back on Pest through a runner that works around the package's custom `vendor-dir`, `test:types` is clean at phpstan `level: max` against a committed baseline, and the six long-unformatted files are formatted. CI enforces all three, and `Run Lint` is now the dry-run form — it previously ran php-cs-fixer in *fix* mode, so it could never fail.

Coverage is now generated, uploaded to Codecov and shown as a README badge: 25.45% overall, with the code added here at 81–100%.

---
## Tests

The suite went from one placeholder test to **108 tests / 208 assertions**, covering ID token verification and its failure modes, the full authorization and callback flow, `state` and `nonce` handling, DPoP proof structure and key storage, JWKS caching and rotation, and dynamic client registration. Verified on PHP 8.2 and 8.4.

The suite had been disabled in CI because Pest's binary resolves its autoloader from a hardcoded `vendor/`, which this package does not have — it sets `vendor-dir` to `server_vendor` — so `pest` could not start here at all. `composer test:unit` now goes through `scripts/pest-runner.php`, adopted from storefront, which bridges that for the duration of the run. CI runs the tests again, plus static analysis, lint and coverage.

---
## Upgrading

- Root `composer.json`: `"fleetbase/solid-api": "^0.0.8"`. A `^0.0.7` constraint will not pick this up.
- `exchangeCodeForTokens($code, $state)` now requires the `state` from the callback. The signature is unchanged, but a missing one throws — without it there is no CSRF control on the callback.
- `Jumbojett\OpenIDConnectClientException` is replaced by `Fleetbase\Solid\Exceptions\OpenIDConnectClientException`, same name and same `\Exception` parent.
- If `FILESYSTEM_DRIVER` points at S3 or GCS, check that bucket for existing `solid/dpop_keys_*.json` objects and delete them. The automatic migration only covers the disk the application is currently configured with.
