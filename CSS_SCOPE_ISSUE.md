# CSS Scope Issue - Root Cause Analysis

## The Problem

Access tokens have `"scope": ""` (empty) even though:
- ✅ Client is registered with `"scope":"openid webid offline_access"`
- ✅ CSS server supports `["openid", "profile", "offline_access", "webid"]`
- ✅ Our code requests `['openid', 'webid', 'offline_access']`

## Investigation Findings

### 1. Client Registration (CORRECT)

File: `/data/.internal/idp/adapter/Client/jwRIERi9-RW7LU_42zM3f$.json`

```json
{
  "client_id": "jwRIERi9-RW7LU_42zM3f",
  "client_name": "Fleetbase-v2",
  "scope": "openid webid offline_access",  ← Registered correctly
  "dpop_bound_access_tokens": false  ← Should this be true?
}
```

### 2. CSS Server Configuration (CORRECT)

File: `/config/config/identity/handler/base/provider-factory.json`

```json
{
  "scopes": ["openid", "profile", "offline_access", "webid"],  ← Server supports these
  "features": {
    "dPoP": { "enabled": true }
  }
}
```

### 3. Grant Storage (ISSUE FOUND!)

File: `/data/.internal/idp/adapter/Grant/-HGiJLKCUJ7CZcU0ePOWQdw84p_WSNKWp3ajB3Q_mf3$.json`

```json
{
  "accountId": "http://solid:3000/test/profile/card#me",
  "clientId": "jwRIERi9-RW7LU_42zM3f",
  "openid": {
    "scope": "openid webid"  ← Only these two! Missing offline_access!
  }
}
```

**Problem:** The grant only includes `"openid webid"`, not `"offline_access"`.

### 4. Access Token (RESULT)

From logs:
```json
{
  "webid": "http://solid:3000/test/profile/card#me",
  "scope": "",  ← Empty!
  "client_id": "jwRIERi9-RW7LU_42zM3f"
}
```

## Root Cause

The scope is stored in the Grant under the `"openid"` key:
```json
"openid": {"scope": "openid webid"}
```

But CSS might not be extracting it correctly when issuing access tokens, resulting in empty scope.

## Possible Solutions

### Option 1: Fix Grant Scope Storage

CSS might be storing scopes incorrectly. This could be:
- A CSS bug
- A configuration issue
- Expected behavior for certain grant types

### Option 2: Use Different Grant Type

The current grant type might not support scopes properly. Try:
- Client credentials grant (already tried, same issue)
- Refresh token flow
- Different authorization parameters

### Option 3: Manual Scope Injection

Modify CSS code or configuration to ensure scopes are included in access tokens.

### Option 4: Workaround - Use ACL Without Scope

Since the ACL is already correct and grants full permissions, the issue is that CSS requires `webid` scope to authenticate as the WebID.

**Workaround:** Use a different authentication method that doesn't require scope:
- CSS credential tokens (tried, same scope issue)
- Direct WebID authentication
- Bearer tokens without scope validation

## Next Steps

1. **Check if `dpop_bound_access_tokens` should be true**
   - Current: `false`
   - Might affect scope handling

2. **Verify authorization request includes scope parameter**
   - Check if scope is being sent during auth code exchange
   - Verify it's not being filtered out

3. **Test with explicit scope in token request**
   - Add scope parameter to token exchange request
   - See if CSS honors it

4. **Check CSS logs for scope-related warnings**
   - CSS might be logging why scopes are being dropped

## CSS Configuration Files Analyzed

- `/config/config/oidc.json` - Main OIDC config
- `/config/config/identity/oidc/default.json` - OIDC handler
- `/config/config/identity/handler/base/provider-factory.json` - Scope definitions
- `/config/config/ldp/metadata-writer/writers/www-auth.json` - WWW-Authenticate header
- `/data/.internal/idp/adapter/Client/*` - Client registrations
- `/data/.internal/idp/adapter/Grant/*` - Authorization grants

## Conclusion

The issue is NOT with:
- ❌ Client registration (correct)
- ❌ Server configuration (correct)
- ❌ Our code (correct)

The issue IS with:
- ✅ Grant scope storage (only `"openid webid"`, missing `"offline_access"`)
- ✅ Access token scope extraction (returns empty instead of grant scopes)

This appears to be a CSS behavior or bug where scopes are not properly propagated from grants to access tokens.
