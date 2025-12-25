<?php

namespace Fleetbase\Solid\Services;

use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Support\Facades\Log;

class AclService
{
    protected PodService $podService;

    public function __construct(PodService $podService)
    {
        $this->podService = $podService;
    }

    /**
     * Check if the pod root has write/append permissions
     */
    public function hasWritePermissions(SolidIdentity $identity, string $podUrl): bool
    {
        try {
            $response = $identity->request('get', $podUrl);
            
            if (!$response->successful()) {
                Log::warning('[ACL CHECK FAILED]', [
                    'pod_url' => $podUrl,
                    'status' => $response->status(),
                ]);
                return false;
            }

            $wacAllow = $response->header('WAC-Allow');
            Log::info('[ACL CHECK]', [
                'pod_url' => $podUrl,
                'wac_allow' => $wacAllow,
            ]);

            // Check if WAC-Allow header includes write or append
            if ($wacAllow) {
                return str_contains($wacAllow, 'write') || str_contains($wacAllow, 'append');
            }

            return false;
        } catch (\Exception $e) {
            Log::error('[ACL CHECK ERROR]', [
                'pod_url' => $podUrl,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Update the pod root ACL to grant write permissions
     */
    public function grantWritePermissions(SolidIdentity $identity, string $podUrl, string $webId): bool
    {
        try {
            $aclUrl = rtrim($podUrl, '/') . '/.acl';
            
            // Generate ACL Turtle document
            $aclTurtle = $this->generateAclDocument($podUrl, $webId);
            
            Log::info('[ACL UPDATE]', [
                'acl_url' => $aclUrl,
                'webid' => $webId,
            ]);

            // PUT the ACL document
            $response = $identity->request('put', $aclUrl, $aclTurtle, [
                'headers' => [
                    'Content-Type' => 'text/turtle',
                ],
            ]);

            if ($response->successful()) {
                Log::info('[ACL UPDATED]', [
                    'acl_url' => $aclUrl,
                    'status' => $response->status(),
                ]);
                return true;
            }

            Log::error('[ACL UPDATE FAILED]', [
                'acl_url' => $aclUrl,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('[ACL UPDATE ERROR]', [
                'pod_url' => $podUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Generate ACL Turtle document
     */
    protected function generateAclDocument(string $podUrl, string $webId): string
    {
        $podUrl = rtrim($podUrl, '/') . '/';
        
        return <<<TURTLE
@prefix acl: <http://www.w3.org/ns/auth/acl#>.

# Full rights for the pod owner
<#owner>
    a acl:Authorization;
    acl:agent <{$webId}>;
    acl:accessTo <{$podUrl}>;
    acl:default <{$podUrl}>;
    acl:mode acl:Read, acl:Write, acl:Control.

# Append and read rights for Fleetbase integration
<#fleetbase>
    a acl:Authorization;
    acl:agent <{$webId}>;
    acl:accessTo <{$podUrl}>;
    acl:default <{$podUrl}>;
    acl:mode acl:Append, acl:Read.

TURTLE;
    }

    /**
     * Ensure pod has write permissions, update ACL if needed
     */
    public function ensureWritePermissions(SolidIdentity $identity, string $podUrl, string $webId): bool
    {
        // Check if already has write permissions
        if ($this->hasWritePermissions($identity, $podUrl)) {
            Log::info('[ACL OK]', ['pod_url' => $podUrl]);
            return true;
        }

        // Need to update ACL
        Log::info('[ACL NEEDS UPDATE]', ['pod_url' => $podUrl]);
        return $this->grantWritePermissions($identity, $podUrl, $webId);
    }
}
