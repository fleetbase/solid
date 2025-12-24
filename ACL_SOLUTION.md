# THE REAL ROOT CAUSE - ACL Permissions

## Summary

The 401 errors are NOT due to authentication or token issues. **The root ACL of each pod does not grant write permissions.**

## Key Facts

1. **CSS default root ACL grants only `acl:Read`** to the authenticated user
2. **Without `acl:Write` or `acl:Append` in the root ACL**, all write operations fail
3. **WAC-Allow header shows `user="read"`** - confirming no write permissions
4. **CSS has no built-in UI** for managing ACLs across pods
5. **Must update ACL programmatically** before first write operation

## The Solution

Before creating any folders or resources, **update the pod's root ACL** to grant write permissions.

### Workflow

1. User logs in via OIDC (get access token + DPoP key)
2. Determine pod root URL (e.g., `http://solid:3000/test/`)
3. **Check if ACL grants write access** by inspecting WAC-Allow header
4. **If no write access, update the root ACL** at `<podRoot>/.acl`
5. After ACL update, create containers/resources

### ACL Document Format

```turtle
@prefix acl: <http://www.w3.org/ns/auth/acl#>.

# Full rights for the pod owner (required)
<#owner>
    a acl:Authorization;
    acl:agent <https://solid.example/user#me>;
    acl:accessTo <https://solid.example/userpod/>;
    acl:default <https://solid.example/userpod/>;
    acl:mode acl:Read, acl:Write, acl:Control.

# Append and read rights for the Fleetbase integration
<#fleetbase>
    a acl:Authorization;
    acl:agent <https://fleetbase.com/agent#me>;
    acl:accessTo <https://solid.example/userpod/>;
    acl:default <https://solid.example/userpod/>;
    acl:mode acl:Append, acl:Read.
```

### Implementation Steps

1. **GET** `<podRoot>` to check WAC-Allow header
2. If lacks `append`/`write`, prepare ACL Turtle document
3. **PUT** `<podRoot>/.acl` with DPoP authentication
4. After successful ACL update, proceed with folder creation

## Why This Matters

- **acl:agent** identifies who gets the permissions (use WebID)
- **acl:accessTo** applies to the pod root
- **acl:default** inherits to all descendants
- **acl:Append** allows creating resources
- **acl:Write** allows updating existing ones

## For Fleetbase Integration

The integration should:
1. Check ACL permissions on first access
2. Prompt user or automatically update ACL
3. Use `acl:Append` mode (safer than full Write)
4. Store ACL update status to avoid repeated checks

This is **not a bug in our code** - it's the expected CSS behavior. Every pod needs explicit ACL configuration for write access.
