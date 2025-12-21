<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Solid\Services\PodService;
use Fleetbase\Solid\Services\ResourceSyncService;
use Fleetbase\Solid\Services\VehicleSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PodController extends BaseController
{
    protected PodService $podService;
    protected VehicleSyncService $vehicleSyncService;
    protected ResourceSyncService $resourceSyncService;

    public function __construct(PodService $podService, VehicleSyncService $vehicleSyncService, ResourceSyncService $resourceSyncService)
    {
        $this->podService          = $podService;
        $this->vehicleSyncService  = $vehicleSyncService;
        $this->resourceSyncService = $resourceSyncService;
    }

    /**
     * Get all pods for the authenticated user.
     */
    public function index(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $pods = $this->podService->getUserPods($identity);

            return response()->json([
                'pods'  => $pods,
                'total' => count($pods),
            ]);
        } catch (\Throwable $e) {
            Log::error('[POD INDEX ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new pod.
     */
    public function create(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $request->validate([
                'name'        => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
            ]);

            $podName     = $request->input('name');
            $description = $request->input('description');

            $pod = $this->podService->createPod($identity, $podName, $description);

            return response()->json([
                'success' => true,
                'pod'     => $pod,
                'message' => "Pod '{$podName}' created successfully",
            ]);
        } catch (\Throwable $e) {
            Log::error('[POD CREATE ERROR]', [
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
     * Get pod contents.
     */
    public function show(Request $request, string $podId)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $contents = $this->podService->getPodContents($identity, $podId);

            return response()->json([
                'pod_id'   => $podId,
                'contents' => $contents,
            ]);
        } catch (\Throwable $e) {
            Log::error('[POD SHOW ERROR]', [
                'pod_id' => $podId,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a pod.
     */
    public function destroy(Request $request, string $podId)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $result = $this->podService->deletePod($identity, $podId);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Pod deleted successfully' : 'Failed to delete pod',
            ]);
        } catch (\Throwable $e) {
            Log::error('[POD DELETE ERROR]', [
                'pod_id' => $podId,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get vehicles available for sync.
     */
    public function getVehiclesForSync(Request $request)
    {
        try {
            $vehicles = $this->vehicleSyncService->getAvailableVehicles();

            return response()->json([
                'vehicles' => $vehicles,
                'total'    => count($vehicles),
            ]);
        } catch (\Throwable $e) {
            Log::error('[VEHICLES FOR SYNC ERROR]', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync selected vehicles to a pod.
     */
    public function syncVehicles(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $request->validate([
                'pod_url'       => 'required|string',
                'vehicle_ids'   => 'required|array',
                'vehicle_ids.*' => 'required|string',
            ]);

            $podUrl     = $request->input('pod_url');
            $vehicleIds = $request->input('vehicle_ids');

            $result = $this->vehicleSyncService->syncVehiclesToPod(
                $identity,
                $podUrl,
                $vehicleIds
            );

            return response()->json([
                'success'      => true,
                'synced_count' => $result['synced_count'],
                'failed_count' => $result['failed_count'],
                'details'      => $result['details'],
                'message'      => "Synced {$result['synced_count']} vehicles to pod",
            ]);
        } catch (\Throwable $e) {
            Log::error('[VEHICLE SYNC ERROR]', [
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
     * Get sync status for a pod.
     */
    public function getSyncStatus(Request $request, string $podId)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $status = $this->vehicleSyncService->getSyncStatus($identity, $podId);

            return response()->json($status);
        } catch (\Throwable $e) {
            Log::error('[SYNC STATUS ERROR]', [
                'pod_id' => $podId,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import Fleetops resources into a pod.
     */
    public function importResources(Request $request, string $podId)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $request->validate([
                'resource_types' => 'required|array',
                'resource_types.*' => 'in:vehicles,drivers,contacts,orders',
            ]);

            $resourceTypes = $request->input('resource_types');
            
            // Get pod URL from pod ID
            $pods = $this->podService->getUserPods($identity);
            $pod = collect($pods)->firstWhere('id', $podId);
            
            if (!$pod) {
                return response()->json([
                    'success' => false,
                    'error' => 'Pod not found',
                ], 404);
            }

            $podUrl = $pod['url'];

            Log::info('[IMPORTING RESOURCES]', [
                'pod_id' => $podId,
                'pod_url' => $podUrl,
                'resource_types' => $resourceTypes,
            ]);

            $result = $this->resourceSyncService->importResources($identity, $podUrl, $resourceTypes);

            return response()->json([
                'success' => true,
                'imported' => $result['imported'],
                'imported_count' => $result['total_count'],
                'errors' => $result['errors'],
                'message' => "Successfully imported {$result['total_count']} resources",
            ]);
        } catch (\Throwable $e) {
            Log::error('[IMPORT RESOURCES ERROR]', [
                'pod_id' => $podId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
