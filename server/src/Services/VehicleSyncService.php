<?php

namespace Fleetbase\Solid\Services;

use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VehicleSyncService
{
    /**
     * Get vehicles available for sync.
     */
    public function getAvailableVehicles(): array
    {
        try {
            $vehicles = Vehicle::with(['driver', 'vendor', 'category'])
                ->where('company_uuid', session('company'))
                ->get();

            return $vehicles->map(function ($vehicle) {
                return [
                    'id'           => $vehicle->uuid,
                    'display_name' => $this->getVehicleDisplayName($vehicle),
                    'make'         => $vehicle->make,
                    'model'        => $vehicle->model,
                    'year'         => $vehicle->year,
                    'plate_number' => $vehicle->plate_number,
                    'vin'          => $vehicle->vin,
                    'status'       => $vehicle->status,
                    'driver_name'  => $vehicle->driver?->name,
                    'vendor_name'  => $vehicle->vendor?->name,
                    'last_seen'    => $vehicle->updated_at?->diffForHumans(),
                    'sync_status'  => 'not_synced', // TODO: Track actual sync status
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::error('[GET AVAILABLE VEHICLES ERROR]', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync selected vehicles to a pod.
     */
    public function syncVehiclesToPod(SolidIdentity $identity, string $podUrl, array $vehicleIds): array
    {
        $syncedCount = 0;
        $failedCount = 0;
        $details     = [];

        try {
            $vehicles = Vehicle::with(['driver', 'vendor', 'category'])
                ->whereIn('uuid', $vehicleIds)
                ->where('company_uuid', session('company'))
                ->get();

            foreach ($vehicles as $vehicle) {
                try {
                    $this->syncSingleVehicle($identity, $podUrl, $vehicle);
                    $syncedCount++;
                    $details[] = [
                        'vehicle_id' => $vehicle->uuid,
                        'status'     => 'success',
                        'message'    => 'Synced successfully',
                    ];
                } catch (\Throwable $e) {
                    $failedCount++;
                    $details[] = [
                        'vehicle_id' => $vehicle->uuid,
                        'status'     => 'failed',
                        'message'    => $e->getMessage(),
                    ];

                    Log::error('[VEHICLE SYNC FAILED]', [
                        'vehicle_id' => $vehicle->uuid,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            Log::info('[VEHICLE SYNC COMPLETED]', [
                'pod_url'      => $podUrl,
                'synced_count' => $syncedCount,
                'failed_count' => $failedCount,
            ]);

            return [
                'synced_count' => $syncedCount,
                'failed_count' => $failedCount,
                'details'      => $details,
            ];
        } catch (\Throwable $e) {
            Log::error('[SYNC VEHICLES TO POD ERROR]', [
                'pod_url'     => $podUrl,
                'vehicle_ids' => $vehicleIds,
                'error'       => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync a single vehicle to the pod.
     */
    private function syncSingleVehicle(SolidIdentity $identity, string $podUrl, Vehicle $vehicle): void
    {
        // Generate RDF/Turtle content for the vehicle
        $rdfContent = $this->generateVehicleRDF($vehicle);

        // Create filename
        $filename    = $this->generateVehicleFilename($vehicle);
        $resourceUrl = rtrim($podUrl, '/') . '/' . $filename;

        // Store the vehicle data in the pod
        $response = $identity->request('put', $resourceUrl, $rdfContent, [
            'headers' => [
                'Content-Type' => 'text/turtle',
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to store vehicle data: ' . $response->body());
        }

        Log::info('[VEHICLE SYNCED]', [
            'vehicle_id'   => $vehicle->uuid,
            'resource_url' => $resourceUrl,
            'status'       => $response->status(),
        ]);
    }

    /**
     * Generate RDF/Turtle content for a vehicle.
     */
    private function generateVehicleRDF(Vehicle $vehicle): string
    {
        $turtle = "@prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> .\n";
        $turtle .= "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n";
        $turtle .= "@prefix dc: <http://purl.org/dc/terms/> .\n";
        $turtle .= "@prefix foaf: <http://xmlns.com/foaf/0.1/> .\n";
        $turtle .= "@prefix vehicle: <http://fleetbase.io/ontology/vehicle#> .\n";
        $turtle .= "@prefix fleet: <http://fleetbase.io/ontology/fleet#> .\n\n";

        $vehicleUri = "<#vehicle-{$vehicle->uuid}>";

        $turtle .= "$vehicleUri a vehicle:Vehicle ;\n";
        $turtle .= "    dc:identifier \"{$vehicle->uuid}\" ;\n";

        if ($vehicle->make) {
            $turtle .= "    vehicle:make \"{$vehicle->make}\" ;\n";
        }

        if ($vehicle->model) {
            $turtle .= "    vehicle:model \"{$vehicle->model}\" ;\n";
        }

        if ($vehicle->year) {
            $turtle .= "    vehicle:year \"{$vehicle->year}\" ;\n";
        }

        if ($vehicle->trim) {
            $turtle .= "    vehicle:trim \"{$vehicle->trim}\" ;\n";
        }

        if ($vehicle->type) {
            $turtle .= "    vehicle:type \"{$vehicle->type}\" ;\n";
        }

        if ($vehicle->plate_number) {
            $turtle .= "    vehicle:plateNumber \"{$vehicle->plate_number}\" ;\n";
        }

        if ($vehicle->vin) {
            $turtle .= "    vehicle:vin \"{$vehicle->vin}\" ;\n";
        }

        if ($vehicle->status) {
            $turtle .= "    fleet:status \"{$vehicle->status}\" ;\n";
        }

        if ($vehicle->online !== null) {
            $onlineStatus = $vehicle->online ? 'true' : 'false';
            $turtle .= "    fleet:online \"$onlineStatus\"^^<http://www.w3.org/2001/XMLSchema#boolean> ;\n";
        }

        // Add location if available
        if ($vehicle->location) {
            $turtle .= "    fleet:location [\n";
            $turtle .= "        a fleet:Location ;\n";

            if (isset($vehicle->location['coordinates'])) {
                $coords = $vehicle->location['coordinates'];
                if (isset($coords[0]) && isset($coords[1])) {
                    $turtle .= "        fleet:longitude \"{$coords[0]}\"^^<http://www.w3.org/2001/XMLSchema#decimal> ;\n";
                    $turtle .= "        fleet:latitude \"{$coords[1]}\"^^<http://www.w3.org/2001/XMLSchema#decimal> ;\n";
                }
            }

            $turtle .= "    ] ;\n";
        }

        // Add driver relationship
        if ($vehicle->driver) {
            $turtle .= "    fleet:assignedDriver [\n";
            $turtle .= "        a foaf:Person ;\n";
            $turtle .= "        foaf:name \"{$vehicle->driver->name}\" ;\n";
            $turtle .= "        dc:identifier \"{$vehicle->driver->uuid}\" ;\n";
            $turtle .= "    ] ;\n";
        }

        // Add vendor relationship
        if ($vehicle->vendor) {
            $turtle .= "    fleet:vendor [\n";
            $turtle .= "        a fleet:Vendor ;\n";
            $turtle .= "        foaf:name \"{$vehicle->vendor->name}\" ;\n";
            $turtle .= "        dc:identifier \"{$vehicle->vendor->uuid}\" ;\n";
            $turtle .= "    ] ;\n";
        }

        // Add metadata
        $turtle .= "    dc:created \"{$vehicle->created_at->toISOString()}\" ;\n";
        $turtle .= "    dc:modified \"{$vehicle->updated_at->toISOString()}\" ;\n";
        $turtle .= '    fleet:syncedAt "' . now()->toISOString() . "\" ;\n";
        $turtle .= "    fleet:syncedFrom \"fleetbase\" .\n";

        return $turtle;
    }

    /**
     * Generate filename for vehicle resource.
     */
    private function generateVehicleFilename(Vehicle $vehicle): string
    {
        $identifier = $vehicle->plate_number ?: $vehicle->vin ?: $vehicle->uuid;
        $slug       = Str::slug($identifier);

        return "vehicle-{$slug}.ttl";
    }

    /**
     * Get vehicle display name.
     */
    private function getVehicleDisplayName(Vehicle $vehicle): string
    {
        $parts = array_filter([
            $vehicle->year,
            $vehicle->make,
            $vehicle->model,
            $vehicle->trim,
        ]);

        $name = implode(' ', $parts);

        if ($vehicle->plate_number) {
            $name .= " ({$vehicle->plate_number})";
        }

        return $name ?: "Vehicle {$vehicle->uuid}";
    }

    /**
     * Get sync status for a pod.
     */
    public function getSyncStatus(SolidIdentity $identity, string $podId): array
    {
        try {
            // This would typically check a sync status table or cache
            // For now, return a basic status
            return [
                'pod_id'          => $podId,
                'last_sync'       => null,
                'total_vehicles'  => 0,
                'synced_vehicles' => 0,
                'failed_vehicles' => 0,
                'status'          => 'ready',
            ];
        } catch (\Throwable $e) {
            Log::error('[GET SYNC STATUS ERROR]', [
                'pod_id' => $podId,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
