<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Solid\Services\PodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContainerController extends Controller
{
    protected PodService $podService;

    public function __construct(PodService $podService)
    {
        $this->podService = $podService;
    }

    /**
     * List containers in the user's pod.
     */
    public function index(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $podUrl = $this->podService->getPodUrlFromWebId($identity->webid);
            $containers = $this->podService->getContainers($identity, $podUrl);

            return response()->json([
                'success' => true,
                'containers' => $containers,
            ]);
        } catch (\Throwable $e) {
            Log::error('[LIST CONTAINERS ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new container in the user's pod.
     */
    public function create(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $request->validate([
                'name' => 'required|string|regex:/^[a-z0-9-]+$/',
            ]);

            $containerName = $request->input('name');
            $podUrl = $this->podService->getPodUrlFromWebId($identity->webid);
            $containerUrl = rtrim($podUrl, '/') . '/' . $containerName . '/';

            Log::info('[CREATING CONTAINER]', [
                'container_url' => $containerUrl,
                'webid' => $identity->webid,
            ]);

            // Create the container
            $response = $identity->request('put', $containerUrl, '', [
                'headers' => [
                    'Content-Type' => 'text/turtle',
                    'Link' => '<http://www.w3.org/ns/ldp#BasicContainer>; rel="type"',
                ],
            ]);

            if (!$response->successful()) {
                throw new \Exception("Failed to create container: {$response->body()}");
            }

            return response()->json([
                'success' => true,
                'container' => [
                    'name' => $containerName,
                    'url' => $containerUrl,
                ],
                'message' => "Container '{$containerName}' created successfully",
            ]);
        } catch (\Throwable $e) {
            Log::error('[CREATE CONTAINER ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a container from the user's pod.
     */
    public function destroy(Request $request, string $containerName)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $podUrl = $this->podService->getPodUrlFromWebId($identity->webid);
            $containerUrl = rtrim($podUrl, '/') . '/' . $containerName . '/';

            Log::info('[DELETING CONTAINER]', [
                'container_url' => $containerUrl,
                'webid' => $identity->webid,
            ]);

            // Delete the container
            $response = $identity->request('delete', $containerUrl);

            if (!$response->successful() && $response->status() !== 404) {
                throw new \Exception("Failed to delete container: {$response->body()}");
            }

            return response()->json([
                'success' => true,
                'message' => "Container '{$containerName}' deleted successfully",
            ]);
        } catch (\Throwable $e) {
            Log::error('[DELETE CONTAINER ERROR]', [
                'container' => $containerName,
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
