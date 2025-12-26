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

    /**
     * Ensure a folder has proper ACL permissions after creation.
     *
     * @param SolidIdentity $identity
     * @param string $folderUrl The folder URL (must end with /)
     * @param string $webId The WebID to grant permissions to
     * @return bool
     */
    public function ensureFolderPermissions(SolidIdentity $identity, string $folderUrl, string $webId): bool
    {
        try {
            // Ensure folder URL ends with /
            $folderUrl = rtrim($folderUrl, '/') . '/';
            $aclUrl = $folderUrl . '.acl';

            Log::info('[ACL] Ensuring folder permissions', [
                'folder_url' => $folderUrl,
                'acl_url' => $aclUrl,
                'webid' => $webId,
            ]);

            // Check if ACL already exists and has write permissions
            if ($this->hasFolderWritePermissions($identity, $folderUrl, $webId)) {
                Log::info('[ACL] Folder already has write permissions', ['folder_url' => $folderUrl]);
                return true;
            }

            // Create ACL with full permissions for the owner
            $aclContent = $this->generateFolderAcl($folderUrl, $webId);
            
            return $this->createFolderAcl($identity, $aclUrl, $aclContent);
        } catch (\Throwable $e) {
            Log::error('[ACL] Failed to ensure folder permissions', [
                'folder_url' => $folderUrl,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if a folder has write permissions.
     *
     * @param SolidIdentity $identity
     * @param string $folderUrl
     * @param string $webId
     * @return bool
     */
    protected function hasFolderWritePermissions(SolidIdentity $identity, string $folderUrl, string $webId): bool
    {
        try {
            $response = $identity->request('head', $folderUrl);

            if (!$response->successful()) {
                return false;
            }

            // Check WAC-Allow header
            $wacAllow = $response->header('WAC-Allow');
            
            if ($wacAllow) {
                Log::debug('[ACL] WAC-Allow header', [
                    'folder_url' => $folderUrl,
                    'wac_allow' => $wacAllow,
                ]);

                // Parse WAC-Allow header: user="read write", public="read"
                if (preg_match('/user="([^"]*)"/i', $wacAllow, $matches)) {
                    $userModes = strtolower($matches[1]);
                    return str_contains($userModes, 'write') || str_contains($userModes, 'append');
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::debug('[ACL] Error checking folder permissions', [
                'folder_url' => $folderUrl,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create an ACL file for a folder.
     *
     * @param SolidIdentity $identity
     * @param string $aclUrl
     * @param string $aclContent
     * @return bool
     */
    protected function createFolderAcl(SolidIdentity $identity, string $aclUrl, string $aclContent): bool
    {
        try {
            $response = $identity->request('put', $aclUrl, $aclContent, [
                'headers' => [
                    'Content-Type' => 'text/turtle',
                ],
            ]);

            if ($response->successful()) {
                Log::info('[ACL] Folder ACL created successfully', [
                    'acl_url' => $aclUrl,
                    'status' => $response->status(),
                ]);
                return true;
            }

            Log::error('[ACL] Failed to create folder ACL', [
                'acl_url' => $aclUrl,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[ACL] Error creating folder ACL', [
                'acl_url' => $aclUrl,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Generate ACL content for a folder with full owner permissions.
     *
     * @param string $folderUrl The folder URL
     * @param string $webId The WebID to grant permissions to
     * @return string
     */
    protected function generateFolderAcl(string $folderUrl, string $webId): string
    {
        return <<<TURTLE
@prefix acl: <http://www.w3.org/ns/auth/acl#>.

<#owner>
    a acl:Authorization;
    acl:agent <{$webId}>;
    acl:accessTo <./>;
    acl:default <./>;
    acl:mode acl:Read, acl:Write, acl:Control.
TURTLE;
    }
}
