<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Solid\Services\PodService;
use Fleetbase\Solid\Services\ResourceSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DataController extends BaseController
{
    protected PodService $podService;
    protected ResourceSyncService $resourceSyncService;

    public function __construct(PodService $podService, ResourceSyncService $resourceSyncService)
    {
        $this->podService          = $podService;
        $this->resourceSyncService = $resourceSyncService;
    }

    /**
     * Get the user's pod data (root level folders and files).
     */
    public function index(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            // Get the user's primary pod URL from their WebID
            $profile = $this->podService->getProfileData($identity);
            $webId = $profile['webid'];
            $podUrl = $this->podService->getPodUrlFromWebId($webId);
            
            Log::info('[DATA INDEX]', [
                'webid' => $webId,
                'pod_url' => $podUrl,
            ]);

            // Get root level contents of the pod
            $contents = $this->podService->getPodContents($identity, $podUrl);

            return response()->json([
                'pod_url'  => $podUrl,
                'contents' => $contents,
            ]);
        } catch (\Throwable $e) {
            Log::error('[DATA INDEX ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get folder contents by slug.
     */
    public function showFolder(Request $request, string $slug)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $profile = $this->podService->getProfileData($identity);
            $webId = $profile['webid'];
            $podUrl = $this->podService->getPodUrlFromWebId($webId);
            $folderUrl = rtrim($podUrl, '/') . '/' . ltrim($slug, '/');

            Log::info('[FOLDER SHOW]', [
                'slug' => $slug,
                'folder_url' => $folderUrl,
            ]);

            $contents = $this->podService->getPodContents($identity, $folderUrl);

            return response()->json([
                'folder_url' => $folderUrl,
                'slug'       => $slug,
                'contents'   => $contents,
            ]);
        } catch (\Throwable $e) {
            Log::error('[FOLDER SHOW ERROR]', [
                'slug'  => $slug,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new folder in the pod.
     */
    public function createFolder(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $request->validate([
                'name' => 'required|string|max:255',
                'path' => 'nullable|string',
            ]);

            $folderName = $request->input('name');
            $path = $request->input('path', '');

            $profile = $this->podService->getProfileData($identity);
            $webId = $profile['webid'];
            $podUrl = $this->podService->getPodUrlFromWebId($webId);
            
            // Build folder URL, avoiding double slashes
            $folderUrl = rtrim($podUrl, '/') . '/';
            if (!empty($path)) {
                $folderUrl .= trim($path, '/') . '/';
            }
            $folderUrl .= $folderName . '/';

            Log::info('[FOLDER CREATE]', [
                'name' => $folderName,
                'path' => $path,
                'folder_url' => $folderUrl,
            ]);

            $result = $this->podService->createFolder($identity, $folderUrl);

            return response()->json([
                'success'    => $result,
                'folder_url' => $folderUrl,
                'message'    => "Folder '{$folderName}' created successfully",
            ]);
        } catch (\Throwable $e) {
            Log::error('[FOLDER CREATE ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a folder or file.
     */
    public function deleteItem(Request $request, string $type, string $slug)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $profile = $this->podService->getProfileData($identity);
            $webId = $profile['webid'];
            $podUrl = $this->podService->getPodUrlFromWebId($webId);
            $itemUrl = rtrim($podUrl, '/') . '/' . ltrim($slug, '/');

            // Add trailing slash for folders
            if ($type === 'folder') {
                $itemUrl = rtrim($itemUrl, '/') . '/';
            }

            Log::info('[ITEM DELETE]', [
                'type' => $type,
                'slug' => $slug,
                'item_url' => $itemUrl,
            ]);

            $result = $this->podService->deleteResource($identity, $itemUrl);

            return response()->json([
                'success' => $result,
                'message' => $result ? ucfirst($type) . ' deleted successfully' : 'Failed to delete ' . $type,
            ]);
        } catch (\Throwable $e) {
            Log::error('[ITEM DELETE ERROR]', [
                'type'  => $type,
                'slug'  => $slug,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import Fleetops resources into the user's pod.
     */
    public function importResources(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $request->validate([
                'resource_types'   => 'required|array',
                'resource_types.*' => 'in:vehicles,drivers,contacts,orders',
            ]);

            $resourceTypes = $request->input('resource_types');

            // Use the authenticated user's pod (from their WebID)
            $profile = $this->podService->getProfileData($identity);
            $webId = $profile['webid'];
            $podUrl = $this->podService->getPodUrlFromWebId($webId);

            Log::info('[IMPORTING RESOURCES]', [
                'pod_url'        => $podUrl,
                'webid'          => $webId,
                'resource_types' => $resourceTypes,
            ]);

            $result = $this->resourceSyncService->importResources($identity, $podUrl, $resourceTypes);

            return response()->json([
                'success'        => true,
                'imported'       => $result['imported'],
                'imported_count' => $result['total_count'],
                'errors'         => $result['errors'],
                'message'        => "Successfully imported {$result['total_count']} resources",
            ]);
        } catch (\Throwable $e) {
            Log::error('[IMPORT RESOURCES ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
