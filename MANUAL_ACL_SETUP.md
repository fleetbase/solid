# Manual ACL Setup Guide

## The Problem

CSS is not granting the `webid` scope even when requested during client registration and authentication. This means:
- Access tokens have `"scope": ""` (empty)
- Cannot programmatically update ACLs (requires `webid` scope)
- Must manually set up root ACL with proper permissions

## The Solution

Manually create the root ACL file for each pod using one of these methods:

### Method 1: Using curl (Recommended)

```bash
# 1. Create ACL file
cat > /tmp/root.acl << 'EOF'
@prefix acl: <http://www.w3.org/ns/auth/acl#>.

<#owner>
    a acl:Authorization;
    acl:agent <http://solid:3000/test/profile/card#me>;
    acl:accessTo <http://solid:3000/test/>;
    acl:default <http://solid:3000/test/>;
    acl:mode acl:Read, acl:Write, acl:Control.
EOF

# 2. Upload to CSS (requires admin access or direct file system access)
curl -X PUT \
  -H "Content-Type: text/turtle" \
  --data-binary @/tmp/root.acl \
  http://solid:3000/test/.acl
```

### Method 2: Direct File System Access

If you have access to the CSS server file system:

```bash
# Navigate to the pod directory
cd /path/to/css/data/test/

# Create .acl file
cat > .acl << 'EOF'
@prefix acl: <http://www.w3.org/ns/auth/acl#>.

<#owner>
    a acl:Authorization;
    acl:agent <http://solid:3000/test/profile/card#me>;
    acl:accessTo <http://solid:3000/test/>;
    acl:default <http://solid:3000/test/>;
    acl:mode acl:Read, acl:Write, acl:Control.
EOF

# Set proper permissions
chmod 644 .acl
```

### Method 3: CSS Admin API (if available)

If your CSS instance has an admin API:

```bash
# Use admin credentials to create ACL
curl -X PUT \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: text/turtle" \
  --data-binary @root.acl \
  http://solid:3000/admin/pods/test/.acl
```

## ACL Template

Replace `<WEBID>` and `<POD_URL>` with actual values:

```turtle
@prefix acl: <http://www.w3.org/ns/auth/acl#>.

# Full control for the pod owner
<#owner>
    a acl:Authorization;
    acl:agent <WEBID>;
    acl:accessTo <POD_URL>;
    acl:default <POD_URL>;
    acl:mode acl:Read, acl:Write, acl:Control.
```

**Example for test pod:**
- WebID: `http://solid:3000/test/profile/card#me`
- Pod URL: `http://solid:3000/test/`

## Verification

After setting up the ACL, verify it works:

```bash
# Check WAC-Allow header (should now include "write")
curl -I http://solid:3000/test/

# Expected response:
# WAC-Allow: user="read write append control",public="read"
```

## For Fleetbase Integration

Once the root ACL is set up:

1. ✅ User can create folders
2. ✅ User can import resources
3. ✅ All descendants inherit permissions (via `acl:default`)
4. ✅ No need for programmatic ACL updates

## Why This Is Necessary

CSS does not grant `webid` scope by default, which means:
- Cannot use OIDC tokens to update ACLs programmatically
- Root ACL must be created manually during pod provisioning
- This is expected behavior for CSS security model

## Long-Term Solution

For production:
1. Configure CSS to grant `webid` scope (server configuration)
2. Or create root ACL during pod creation (provisioning hook)
3. Or use CSS admin API to set up ACLs automatically

## Current Status

- ✅ Authentication works (DPoP + OIDC)
- ✅ Read operations work
- ❌ Write operations fail (no ACL permissions)
- ❌ Cannot update ACL (no `webid` scope)

**Action Required:** Manually create root ACL for each pod using one of the methods above.
