<?php

namespace Fleetbase\Solid\Services;

use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Solid\Client\SolidClient;
use Illuminate\Support\Facades\Log;

class ResourceSyncService
{
    /**
     * Import resources into a pod.
     *
     * @param SolidIdentity $identity
     * @param string $podUrl
     * @param array $resourceTypes
     * @return array
     */
    public function importResources(SolidIdentity $identity, string $podUrl, array $resourceTypes): array
    {
        $imported = [];
        $errors = [];
        $totalCount = 0;

        foreach ($resourceTypes as $resourceType) {
            try {
                Log::info('[IMPORTING RESOURCE TYPE]', ['type' => $resourceType, 'pod_url' => $podUrl]);
                
                $result = $this->importResourceType($identity, $podUrl, $resourceType);
                $imported[$resourceType] = $result['count'];
                $totalCount += $result['count'];
                
                Log::info('[RESOURCE TYPE IMPORTED]', [
                    'type' => $resourceType,
                    'count' => $result['count'],
                ]);
            } catch (\Throwable $e) {
                Log::error('[RESOURCE IMPORT ERROR]', [
                    'type' => $resourceType,
                    'error' => $e->getMessage(),
                ]);
                $errors[$resourceType] = $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
            'total_count' => $totalCount,
        ];
    }

    /**
     * Import a specific resource type.
     *
     * @param SolidIdentity $identity
     * @param string $podUrl
     * @param string $resourceType
     * @return array
     */
    protected function importResourceType(SolidIdentity $identity, string $podUrl, string $resourceType): array
    {
        // Get resources from Fleetops
        $resources = $this->getFleetopsResources($resourceType);
        
        if (empty($resources)) {
            return ['count' => 0];
        }

        // Create container for this resource type
        $containerUrl = rtrim($podUrl, '/') . '/' . $resourceType . '/';
        $this->createContainer($identity, $containerUrl);

        // Import each resource
        $count = 0;
        foreach ($resources as $resource) {
            try {
                $turtle = $this->convertToRDF($resourceType, $resource);
                $resourceUrl = $containerUrl . $resource->public_id . '.ttl';
                
                $this->storeResource($identity, $resourceUrl, $turtle);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('[RESOURCE IMPORT FAILED]', [
                    'type' => $resourceType,
                    'id' => $resource->public_id ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['count' => $count];
    }

    /**
     * Get Fleetops resources by type.
     *
     * @param string $resourceType
     * @return \Illuminate\Support\Collection
     */
    protected function getFleetopsResources(string $resourceType)
    {
        $modelMap = [
            'vehicles' => \Fleetbase\FleetOps\Models\Vehicle::class,
            'drivers' => \Fleetbase\FleetOps\Models\Driver::class,
            'contacts' => \Fleetbase\FleetOps\Models\Contact::class,
            'orders' => \Fleetbase\FleetOps\Models\Order::class,
        ];

        if (!isset($modelMap[$resourceType])) {
            throw new \Exception("Unknown resource type: {$resourceType}");
        }

        $modelClass = $modelMap[$resourceType];
        
        // Get current company's resources
        $companyId = session('company');
        
        Log::info('[FETCHING FLEETOPS RESOURCES]', [
            'resource_type' => $resourceType,
            'model_class' => $modelClass,
            'company_id' => $companyId,
        ]);
        
        if (!$companyId) {
            Log::warning('[NO COMPANY ID]', ['session' => session()->all()]);
            // Try to get from auth user
            $user = auth()->user();
            if ($user && isset($user->company_uuid)) {
                $companyId = $user->company_uuid;
                Log::info('[USING USER COMPANY]', ['company_id' => $companyId]);
            }
        }
        
        if (!$companyId) {
            Log::error('[CANNOT DETERMINE COMPANY]');
            return collect([]);
        }
        
        $query = $modelClass::where('company_uuid', $companyId)
            ->limit(100); // Limit for now to avoid overwhelming the pod
            
        $count = $query->count();
        Log::info('[RESOURCE QUERY]', [
            'resource_type' => $resourceType,
            'count' => $count,
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
        ]);
        
        return $query->get();
    }

    /**
     * Convert a resource to RDF/Turtle format.
     *
     * @param string $resourceType
     * @param mixed $resource
     * @return string
     */
    protected function convertToRDF(string $resourceType, $resource): string
    {
        $baseUri = "http://fleetbase.io/ns/{$resourceType}/";
        $resourceUri = $baseUri . $resource->public_id;

        $turtle = "@prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> .\n";
        $turtle .= "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n";
        $turtle .= "@prefix xsd: <http://www.w3.org/2001/XMLSchema#> .\n";
        $turtle .= "@prefix fb: <http://fleetbase.io/ns/> .\n";
        $turtle .= "@prefix fb{$resourceType}: <{$baseUri}> .\n\n";

        $turtle .= "<{$resourceUri}>\n";
        $turtle .= "    a fb:{$this->getResourceClass($resourceType)} ;\n";
        $turtle .= "    fb:id \"{$resource->public_id}\" ;\n";

        // Add resource-specific properties
        $properties = $this->getResourceProperties($resourceType, $resource);
        foreach ($properties as $property => $value) {
            if ($value !== null && $value !== '') {
                $turtle .= "    fb:{$property} " . $this->formatRDFValue($value) . " ;\n";
            }
        }

        // Remove trailing semicolon and add period
        $turtle = rtrim($turtle, " ;\n") . " .\n";

        return $turtle;
    }

    /**
     * Get RDF class name for resource type.
     *
     * @param string $resourceType
     * @return string
     */
    protected function getResourceClass(string $resourceType): string
    {
        return ucfirst(rtrim($resourceType, 's'));
    }

    /**
     * Get properties for a resource type.
     *
     * @param string $resourceType
     * @param mixed $resource
     * @return array
     */
    protected function getResourceProperties(string $resourceType, $resource): array
    {
        switch ($resourceType) {
            case 'vehicles':
                return [
                    'name' => $resource->name,
                    'make' => $resource->make,
                    'model' => $resource->model,
                    'year' => $resource->year,
                    'vin' => $resource->vin,
                    'plate_number' => $resource->plate_number,
                    'status' => $resource->status,
                    'created_at' => $resource->created_at?->toIso8601String(),
                    'updated_at' => $resource->updated_at?->toIso8601String(),
                ];

            case 'drivers':
                return [
                    'name' => $resource->name,
                    'email' => $resource->email,
                    'phone' => $resource->phone,
                    'license_number' => $resource->drivers_license_number,
                    'status' => $resource->status,
                    'created_at' => $resource->created_at?->toIso8601String(),
                    'updated_at' => $resource->updated_at?->toIso8601String(),
                ];

            case 'contacts':
                return [
                    'name' => $resource->name,
                    'email' => $resource->email,
                    'phone' => $resource->phone,
                    'type' => $resource->type,
                    'created_at' => $resource->created_at?->toIso8601String(),
                    'updated_at' => $resource->updated_at?->toIso8601String(),
                ];

            case 'orders':
                return [
                    'tracking_number' => $resource->public_id,
                    'status' => $resource->status,
                    'type' => $resource->type,
                    'scheduled_at' => $resource->scheduled_at?->toIso8601String(),
                    'created_at' => $resource->created_at?->toIso8601String(),
                    'updated_at' => $resource->updated_at?->toIso8601String(),
                ];

            default:
                return [];
        }
    }

    /**
     * Format a value for RDF.
     *
     * @param mixed $value
     * @return string
     */
    protected function formatRDFValue($value): string
    {
        if (is_numeric($value)) {
            return "\"{$value}\"^^xsd:integer";
        }

        if (is_bool($value)) {
            return $value ? '"true"^^xsd:boolean' : '"false"^^xsd:boolean';
        }

        // Escape quotes and special characters
        $escaped = str_replace(['"', '\\'], ['\\"', '\\\\'], (string)$value);
        return "\"{$escaped}\"";
    }

    /**
     * Create a container in the pod.
     *
     * @param SolidIdentity $identity
     * @param string $containerUrl
     * @return void
     */
    protected function createContainer(SolidIdentity $identity, string $containerUrl): void
    {
        try {
            $response = $identity->request('put', $containerUrl, '', [
                'headers' => [
                    'Content-Type' => 'text/turtle',
                    'Link' => '<http://www.w3.org/ns/ldp#BasicContainer>; rel="type"',
                ],
            ]);

            Log::info('[CONTAINER CREATED]', [
                'url' => $containerUrl,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            // Container might already exist, that's okay
            Log::debug('[CONTAINER CREATION SKIPPED]', [
                'url' => $containerUrl,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store a resource in the pod.
     *
     * @param SolidIdentity $identity
     * @param string $resourceUrl
     * @param string $turtle
     * @return void
     */
    protected function storeResource(SolidIdentity $identity, string $resourceUrl, string $turtle): void
    {
        $response = $identity->request('put', $resourceUrl, $turtle, [
            'headers' => [
                'Content-Type' => 'text/turtle',
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception("Failed to store resource: {$response->body()}");
        }

        Log::debug('[RESOURCE STORED]', [
            'url' => $resourceUrl,
            'status' => $response->status(),
        ]);
    }
}
