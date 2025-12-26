<?php

namespace Fleetbase\Solid\Services;

use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PodService
{
    /**
     * Create a new pod with 401 error handling.
     */
    public function createPod(SolidIdentity $identity, string $name, ?string $description = null): array
    {
        try {
            // Get profile data
            $profile = $this->getProfileData($identity);
            $webId   = $profile['webid'];

            // Extract storage location from WebID
            $storageUrl = $this->getStorageUrlFromWebId($webId);

            Log::info('[INFERRED STORAGE FROM WEBID]', [
                'webid'       => $webId,
                'storage_url' => $storageUrl,
            ]);

            // Test the storage location first
            $isValidStorage = $this->testStorageLocation($identity, $storageUrl);

            if (!$isValidStorage) {
                throw new \Exception("Storage location {$storageUrl} is not accessible. Please check your permissions.");
            }

            // Create the pod with multiple methods
            return $this->createPodInStorage($identity, $storageUrl, $name, $description);
        } catch (\Throwable $e) {
            Log::error('[CREATE POD ERROR]', [
                'name'  => $name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Extract storage URL from WebID.
     */
    private function getStorageUrlFromWebId(string $webId): string
    {
        $parsed  = parse_url($webId);
        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];

        if (isset($parsed['port'])) {
            $baseUrl .= ':' . $parsed['port'];
        }

        // Extract username from path like /test/profile/card#me
        $path = $parsed['path'];
        if (preg_match('/^\/([^\/]+)\//', $path, $matches)) {
            $username = $matches[1];

            return $baseUrl . '/' . $username . '/';
        }

        return $baseUrl . '/';
    }

    /**
     * Test if storage location is accessible.
     */
    private function testStorageLocation(SolidIdentity $identity, string $storageUrl): bool
    {
        try {
            $response = $identity->request('head', $storageUrl);

            Log::info('[STORAGE TEST]', [
                'storage_url' => $storageUrl,
                'status'      => $response->status(),
                'headers'     => $response->headers(),
            ]);

            if (!$response->successful()) {
                return false;
            }

            // Parse WAC-Allow for user rights
            $wac = $response->header('WAC-Allow');
            if ($wac && preg_match('/user="([^"]+)"/', $wac, $m)) {
                $perms    = $m[1]; // e.g., read write append control
                $hasWrite = str_contains($perms, 'write') || str_contains($perms, 'append');
                if (!$hasWrite) {
                    Log::warning('[STORAGE NOT WRITABLE]', ['wac_allow' => $wac]);
                    // Still return true so we can attempt creation – CSS sometimes omits full perms here,
                    // but the 401 will be handled and logged later if writes are truly blocked.
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[STORAGE TEST FAILED]', ['storage_url' => $storageUrl, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Create pod in storage location with multiple methods.
     */
    private function createPodInStorage(SolidIdentity $identity, string $storageUrl, string $name, ?string $description = null): array
    {
        $podSlug = Str::slug($name);
        $podUrl  = rtrim($storageUrl, '/') . '/' . $podSlug . '/';

        Log::info('[CREATING POD]', ['name' => $name, 'storage_url' => $storageUrl, 'pod_url' => $podUrl]);

        // Check if identity has CSS credentials for account management API
        $cssAccountService = app(CssAccountService::class);
        
        if ($cssAccountService->hasCredentials($identity)) {
            Log::info('[USING CSS ACCOUNT MANAGEMENT API]');
            
            try {
                // Get WebID and extract issuer from it
                $tokenResponse = $identity->token_response;
                $idToken = data_get($tokenResponse, 'id_token');
                
                if (!$idToken) {
                    throw new \Exception('No ID token available');
                }
                
                $solid = SolidClient::create(['identity' => $identity]);
                $webId = $solid->oidc->getWebIdFromIdToken($idToken);
                
                if (!$webId) {
                    throw new \Exception('Could not extract WebID from ID token');
                }
                
                // Extract issuer from WebID URL
                $parsed = parse_url($webId);
                $issuer = $parsed['scheme'] . '://' . $parsed['host'];
                if (isset($parsed['port'])) {
                    $issuer .= ':' . $parsed['port'];
                }
                
                // Use email/password login to get CSS-Account-Token for pod management
                $email = $identity->css_email;
                $password = decrypt($identity->css_password);
                
                Log::info('[CSS POD CREATION] Logging in with email/password for pod management');
                
                $authorization = $cssAccountService->login($issuer, $email, $password);
                
                if (!$authorization) {
                    throw new \Exception('Failed to login to CSS account for pod creation');
                }
                
                // Get the account API controls to find the pod creation endpoint
                $controlsResponse = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => "CSS-Account-Token {$authorization}",
                ])->get("{$issuer}/.account/");
                
                if (!$controlsResponse->successful()) {
                    throw new \Exception('Failed to get account controls');
                }
                
                $controls = $controlsResponse->json();
                
                Log::info('[CSS ACCOUNT CONTROLS]', ['controls' => $controls]);
                
                $podControlUrl = data_get($controls, 'controls.account.pod');
                
                if (!$podControlUrl) {
                    Log::warning('[POD CONTROL URL NOT FOUND]', ['controls_keys' => array_keys($controls)]);
                    // Fall back to legacy methods
                    throw new \Exception('Pod control URL not found - falling back to legacy methods');
                }
                
                Log::info('[CSS POD CONTROL URL]', ['url' => $podControlUrl]);
                
                // Use the account management API to create pod
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => "CSS-Account-Token {$authorization}",
                    'Content-Type' => 'application/json',
                ])->post($podControlUrl, [
                    'name' => $podSlug,
                ]);
                
                Log::info('[CSS ACCOUNT API RESPONSE]', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                
                if ($response->successful()) {
                    $podData = $response->json();
                    
                    return [
                        'id' => $podSlug,
                        'name' => $name,
                        'url' => $podData['pod'] ?? $podUrl,
                        'description' => $description,
                        'created_at' => now()->toISOString(),
                        'type' => 'pod',
                        'status' => 'created',
                        'method' => 'css_account_api',
                    ];
                }
                
                Log::warning('[CSS ACCOUNT API FAILED]', [
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);
            } catch (\Throwable $e) {
                Log::error('[CSS ACCOUNT API ERROR]', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Fall through to legacy methods
            }
        } else {
            Log::info('[NO CSS CREDENTIALS - USING LEGACY METHODS]');
        }

        // Legacy methods as fallback
        $metadata = $this->generatePodMetadata($name, $description);

        // ---- Method 1: POST to parent (recommended) ----
        try {
            $response = $identity->request('post', $storageUrl, $metadata, [
                'headers' => [
                    'Content-Type'   => 'text/turtle',
                    'Slug'           => $podSlug,
                    'Link'           => '<http://www.w3.org/ns/ldp#BasicContainer>; rel="type"',
                    'If-None-Match'  => '*',
                    'Prefer'         => 'return=representation',
                ],
            ]);

            Log::info('[POST WITH METADATA RESPONSE]', [
                'storage_url' => $storageUrl,
                'status'      => $response->status(),
                'response'    => $response->body(),
            ]);

            if ($response->successful() || in_array($response->status(), [201, 202, 204])) {
                $location = $response->header('Location') ?: $podUrl;

                return [
                    'id'          => $podSlug,
                    'name'        => $name,
                    'url'         => $location,
                    'description' => $description,
                    'created_at'  => now()->toISOString(),
                    'type'        => 'container',
                    'status'      => 'created',
                    'method'      => 'post_metadata',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[POST METADATA METHOD FAILED]', ['error' => $e->getMessage()]);
        }

        // ---- Method 2: POST to parent with empty body (server may auto-assign metadata) ----
        try {
            $response = $identity->request('post', $storageUrl, '', [
                'headers' => [
                    'Content-Type'   => 'text/turtle',
                    'Slug'           => $podSlug,
                    'Link'           => '<http://www.w3.org/ns/ldp#BasicContainer>; rel="type"',
                    'If-None-Match'  => '*',
                ],
            ]);

            Log::info('[POST EMPTY BODY RESPONSE]', [
                'storage_url' => $storageUrl,
                'status'      => $response->status(),
                'response'    => $response->body(),
            ]);

            if ($response->successful() || in_array($response->status(), [201, 202, 204])) {
                $location = $response->header('Location') ?: $podUrl;

                return [
                    'id'          => $podSlug,
                    'name'        => $name,
                    'url'         => $location,
                    'description' => $description,
                    'created_at'  => now()->toISOString(),
                    'type'        => 'container',
                    'status'      => 'created',
                    'method'      => 'post_empty',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[POST EMPTY METHOD FAILED]', ['error' => $e->getMessage()]);
        }

        // ---- Method 3: PUT directly to the container URL (fallback) ----
        try {
            $response = $identity->request('put', $podUrl, $metadata, [
                'headers' => [
                    'Content-Type'   => 'text/turtle',
                    'Link'           => '<http://www.w3.org/ns/ldp#BasicContainer>; rel="type"',
                    'If-None-Match'  => '*',
                ],
            ]);

            Log::info('[PUT WITH METADATA RESPONSE]', [
                'pod_url'  => $podUrl,
                'status'   => $response->status(),
                'response' => $response->body(),
            ]);

            if ($response->successful() || in_array($response->status(), [201, 202, 204])) {
                return [
                    'id'          => $podSlug,
                    'name'        => $name,
                    'url'         => $podUrl,
                    'description' => $description,
                    'created_at'  => now()->toISOString(),
                    'type'        => 'container',
                    'status'      => 'created',
                    'method'      => 'put_metadata',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[PUT METADATA METHOD FAILED]', ['error' => $e->getMessage()]);
        }

        // Final safety
        throw new \Exception('All pod creation methods failed. If you have CSS credentials configured, there may be an issue with the account management API. Otherwise, please set up CSS credentials for pod creation.');
    }

    /**
     * Get all pods for a user.
     */
    public function getUserPods(SolidIdentity $identity): array
    {
        try {
            $profile    = $this->getProfileData($identity);
            $storageUrl = $this->getStorageUrlFromWebId($profile['webid']);
            $webId      = $profile['webid'];

            $pods = [];
            
            // Try to get pods from CSS Account Management API first
            $cssAccountService = app(CssAccountService::class);
            if ($cssAccountService->hasCredentials($identity)) {
                try {
                    // Extract issuer from WebID
                    $parsed = parse_url($webId);
                    $issuer = $parsed['scheme'] . '://' . $parsed['host'];
                    if (isset($parsed['port'])) {
                        $issuer .= ':' . $parsed['port'];
                    }
                    
                    $email = $identity->css_email;
                    $password = decrypt($identity->css_password);
                    
                    $authorization = $cssAccountService->login($issuer, $email, $password);
                    
                    if ($authorization) {
                        // Get account controls
                        $controlsResponse = \Illuminate\Support\Facades\Http::withHeaders([
                            'Authorization' => "CSS-Account-Token {$authorization}",
                        ])->get("{$issuer}/.account/");
                        
                        if ($controlsResponse->successful()) {
                            $controls = $controlsResponse->json();
                            $podControlUrl = data_get($controls, 'controls.account.pod');
                            
                            if ($podControlUrl) {
                                // Get pods from account management API
                                $podsResponse = \Illuminate\Support\Facades\Http::withHeaders([
                                    'Authorization' => "CSS-Account-Token {$authorization}",
                                ])->get($podControlUrl);
                                
                                if ($podsResponse->successful()) {
                                    $podsData = $podsResponse->json();
                                    $accountPods = data_get($podsData, 'pods', []);
                                    
                                    Log::info('[CSS ACCOUNT PODS]', ['pods' => $accountPods]);
                                    
                                    foreach ($accountPods as $podUrl => $accountUrl) {
                                        $podName = $this->extractPodName($podUrl);
                                        $pods[] = [
                                            'id' => Str::slug($podName),
                                            'name' => $podName,
                                            'url' => $podUrl,
                                            'type' => 'pod',
                                            'source' => 'css_account',
                                        ];
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('[CSS ACCOUNT PODS FETCH WARNING]', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Get the main storage pod
            try {
                $podResponse = $identity->request('get', $storageUrl);
                if ($podResponse->successful()) {
                    $podData = $this->parsePodData($storageUrl, $podResponse->body());
                    $pods[]  = $podData;
                }
            } catch (\Throwable $e) {
                Log::warning('[STORAGE POD FETCH WARNING]', [
                    'storage_url' => $storageUrl,
                    'error'       => $e->getMessage(),
                ]);
            }

            // Get containers within storage
            $containers = $this->getContainers($identity, [$storageUrl]);
            $pods       = array_merge($pods, $containers);

            return $pods;
        } catch (\Throwable $e) {
            Log::error('[GET USER PODS ERROR]', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get pod contents.
     */
    public function getPodContents(SolidIdentity $identity, string $podIdOrUrl): array
    {
        try {
            // If it's a URL (starts with http), use it directly
            // Otherwise, look it up as a pod ID (for backward compatibility)
            if (str_starts_with($podIdOrUrl, 'http://') || str_starts_with($podIdOrUrl, 'https://')) {
                $podUrl = $podIdOrUrl;
            } else {
                // Find the pod URL by ID (old multi-pod architecture)
                $pods = $this->getUserPods($identity);
                $pod  = collect($pods)->firstWhere('id', $podIdOrUrl);

                if (!$pod) {
                    throw new \Exception('Pod not found');
                }

                $podUrl = $pod['url'];
            }

            $response = $identity->request('get', $podUrl);

            if (!$response->successful()) {
                throw new \Exception('Failed to fetch pod contents');
            }

            return $this->parseContainerContents($response->body());
        } catch (\Throwable $e) {
            Log::error('[GET POD CONTENTS ERROR]', [
                'pod_id_or_url' => $podIdOrUrl,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete a pod.
     */
    public function deletePod(SolidIdentity $identity, string $podId): bool
    {
        try {
            // Find the pod URL by ID
            $pods = $this->getUserPods($identity);
            $pod  = collect($pods)->firstWhere('id', $podId);

            if (!$pod) {
                throw new \Exception('Pod not found');
            }

            $podUrl = $pod['url'];

            // Delete the pod container
            $response = $identity->request('delete', $podUrl);

            $success = $response->successful();

            Log::info('[POD DELETED]', [
                'pod_id'  => $podId,
                'url'     => $podUrl,
                'success' => $success,
            ]);

            return $success;
        } catch (\Throwable $e) {
            Log::error('[DELETE POD ERROR]', [
                'pod_id' => $podId,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get profile data.
     */
    public function getProfileData(SolidIdentity $identity): array
    {
        $tokenResponse = $identity->token_response;
        $idToken       = data_get($tokenResponse, 'id_token');
        if (!$idToken) {
            throw new \Exception('No ID token available');
        }

        $solid = SolidClient::create(['identity' => $identity]);
        $webId = $solid->oidc->getWebIdFromIdToken($idToken);
        if (!$webId) {
            throw new \Exception('No WebID found');
        }

        // IMPORTANT: fetch the *document* (strip #me)
        $profileDoc = explode('#', $webId, 2)[0];

        $profileResponse = $identity->request('get', $profileDoc, [], [
            'headers' => [
                'Accept' => 'text/turtle, application/ld+json;q=0.9, */*;q=0.1',
            ],
        ]);

        if (!$profileResponse->successful()) {
            throw new \Exception('Failed to fetch profile');
        }

        return [
            'webid'          => $webId,
            'profile_data'   => $profileResponse->body(),
            'parsed_profile' => $this->parseProfile($profileResponse->body()),
        ];
    }

    /**
     * Parse profile data.
     */
    private function parseProfile(string $profileData): array
    {
        $parsed = [
            'name'              => null,
            'email'             => null,
            'storage_locations' => [],
            'inbox'             => null,
        ];

        // Parse storage locations with multiple patterns
        $storagePatterns = [
            '/pim:storage\s+<([^>]+)>/',
            '/solid:storageQuota\s+<([^>]+)>/',
            '/(\w+:)?storage\w*\s+<([^>]+)>/',
            '/ldp:contains\s+<([^>]+)>/', // Sometimes storage is listed as contained resources
        ];

        foreach ($storagePatterns as $pattern) {
            if (preg_match_all($pattern, $profileData, $matches)) {
                $urls                        = isset($matches[2]) ? $matches[2] : $matches[1];
                $parsed['storage_locations'] = array_merge($parsed['storage_locations'], $urls);
            }
        }

        $parsed['storage_locations'] = array_unique($parsed['storage_locations']);

        // Parse name with multiple patterns
        $namePatterns = [
            '/foaf:name\s+"([^"]+)"/',
            '/foaf:name\s+\'([^\']+)\'/',
            '/vcard:fn\s+"([^"]+)"/',
            '/schema:name\s+"([^"]+)"/',
        ];

        foreach ($namePatterns as $pattern) {
            if (preg_match($pattern, $profileData, $matches)) {
                $parsed['name'] = $matches[1];
                break;
            }
        }

        // Parse email with multiple patterns
        $emailPatterns = [
            '/foaf:mbox\s+<mailto:([^>]+)>/',
            '/vcard:hasEmail\s+<mailto:([^>]+)>/',
            '/schema:email\s+"([^"]+)"/',
        ];

        foreach ($emailPatterns as $pattern) {
            if (preg_match($pattern, $profileData, $matches)) {
                $parsed['email'] = $matches[1];
                break;
            }
        }

        // Parse inbox
        if (preg_match('/ldp:inbox\s+<([^>]+)>/', $profileData, $matches)) {
            $parsed['inbox'] = $matches[1];
        }

        Log::info('[PARSED PROFILE]', [
            'storage_locations_count' => count($parsed['storage_locations']),
            'storage_locations'       => $parsed['storage_locations'],
            'name'                    => $parsed['name'],
            'email'                   => $parsed['email'],
        ]);

        return $parsed;
    }

    /**
     * Parse pod data from response.
     */
    private function parsePodData(string $url, string $content): array
    {
        $name = $this->extractPodName($url);
        $id   = Str::slug($name);

        return [
            'id'            => $id,
            'name'          => $name,
            'url'           => $url,
            'type'          => 'storage',
            'containers'    => $this->parseContainerContents($content),
            'size'          => strlen($content),
            'last_modified' => now()->toISOString(),
        ];
    }

    /**
     * Extract pod name from URL.
     */
    private function extractPodName(string $url): string
    {
        $parts = explode('/', rtrim($url, '/'));

        return end($parts) ?: 'Root Storage';
    }

    /**
     * Get containers within storage locations.
     */
    private function getContainers(SolidIdentity $identity, array $storageLocations): array
    {
        $containers = [];

        foreach ($storageLocations as $storageUrl) {
            try {
                $response = $identity->request('get', $storageUrl);
                if ($response->successful()) {
                    $containerUrls = $this->parseContainerUrls($response->body());

                    foreach ($containerUrls as $containerUrl) {
                        $containers[] = [
                            'id'     => Str::slug($this->extractPodName($containerUrl)),
                            'name'   => $this->extractPodName($containerUrl),
                            'url'    => $containerUrl,
                            'type'   => 'container',
                            'parent' => $storageUrl,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[GET CONTAINERS WARNING]', [
                    'storage_url' => $storageUrl,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $containers;
    }

    /**
     * Parse container URLs from RDF content.
     */
    private function parseContainerUrls(string $content): array
    {
        $urls = [];

        // Parse LDP containers
        if (preg_match_all('/<([^>]+)>\s+a\s+ldp:Container/', $content, $matches)) {
            $urls = array_merge($urls, $matches[1]);
        }

        // Parse LDP BasicContainer
        if (preg_match_all('/<([^>]+)>\s+a\s+ldp:BasicContainer/', $content, $matches)) {
            $urls = array_merge($urls, $matches[1]);
        }

        // Parse ldp:contains relationships
        if (preg_match_all('/ldp:contains\s+<([^>]+\/)>/', $content, $matches)) {
            $urls = array_merge($urls, $matches[1]);
        }

        return array_unique($urls);
    }

    /**
     * Parse container contents.
     */
    private function parseContainerContents(string $content): array
    {
        $items = [];

        // Parse contained resources
        if (preg_match_all('/ldp:contains\s+<([^>]+)>/', $content, $matches)) {
            foreach ($matches[1] as $resourceUrl) {
                $items[] = [
                    'url'  => $resourceUrl,
                    'name' => $this->extractPodName($resourceUrl),
                    'type' => substr($resourceUrl, -1) === '/' ? 'container' : 'resource',
                ];
            }
        }

        return $items;
    }

    /**
     * Generate pod metadata in Turtle format.
     */
    private function generatePodMetadata(string $name, ?string $description = null): string
    {
        $turtle = "@prefix dc: <http://purl.org/dc/terms/> .\n";
        $turtle .= "@prefix foaf: <http://xmlns.com/foaf/0.1/> .\n";
        $turtle .= "@prefix solid: <http://www.w3.org/ns/solid/terms#> .\n";
        $turtle .= "@prefix ldp: <http://www.w3.org/ns/ldp#> .\n\n";

        $turtle .= "<> a ldp:BasicContainer, ldp:Container ;\n";
        $turtle .= "   dc:title \"$name\" ;\n";

        if ($description) {
            $turtle .= "   dc:description \"$description\" ;\n";
        }

        $turtle .= '   dc:created "' . now()->toISOString() . "\" .\n";

        return $turtle;
    }

    /**
     * Get pod URL from WebID.
     *
     * @param string $webId
     * @return string
     */
    public function getPodUrlFromWebId(string $webId): string
    {
        // Extract pod URL from WebID
        // WebID format: http://solid:3000/test/profile/card#me
        // Pod URL: http://solid:3000/test/
        
        $parsed = parse_url($webId);
        $path = $parsed['path'] ?? '';
        
        // Remove /profile/card from the path
        $podPath = preg_replace('#/profile/card.*$#', '/', $path);
        
        $podUrl = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $podUrl .= ':' . $parsed['port'];
        }
        $podUrl .= $podPath;
        
        return $podUrl;
    }

    /**
     * Create a folder (container) in the pod.
     */
    public function createFolder(SolidIdentity $identity, string $parentUrl, string $folderName): bool
    {
        try {
            // Ensure parent URL ends with /
            $parentUrl = rtrim($parentUrl, '/') . '/';
            
            Log::info('[CREATE FOLDER]', [
                'parent_url' => $parentUrl,
                'folder_name' => $folderName,
            ]);

            // Create the folder using POST with Slug header (Solid Protocol standard)
            $response = $identity->request('post', $parentUrl, '', [
                'headers' => [
                    'Content-Type' => 'text/turtle',
                    'Link' => '<http://www.w3.org/ns/ldp#BasicContainer>; rel="type"',
                    'Slug' => $folderName,
                ],
            ]);

            if ($response->successful()) {
                $createdUrl = $response->header('Location') ?? $parentUrl . $folderName . '/';
                Log::info('[FOLDER CREATED]', [
                    'folder_url' => $createdUrl,
                    'status' => $response->status(),
                ]);

                // Ensure the folder has proper ACL permissions
                $aclService = app(AclService::class);
                $webId = $identity->webid;
                
                if ($webId) {
                    $aclService->ensureFolderPermissions($identity, $createdUrl, $webId);
                }

                return true;
            }

            Log::error('[FOLDER CREATE FAILED]', [
                'parent_url' => $parentUrl,
                'folder_name' => $folderName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[FOLDER CREATE ERROR]', [
                'folder_url' => $folderUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete a resource (file or folder) from the pod.
     */
    public function deleteResource(SolidIdentity $identity, string $resourceUrl): bool
    {
        try {
            Log::info('[DELETE RESOURCE]', [
                'resource_url' => $resourceUrl,
            ]);

            // Delete the resource using DELETE request
            $response = $identity->request('delete', $resourceUrl);

            if ($response->successful()) {
                Log::info('[RESOURCE DELETED]', [
                    'resource_url' => $resourceUrl,
                    'status' => $response->status(),
                ]);
                return true;
            }

            Log::error('[RESOURCE DELETE FAILED]', [
                'resource_url' => $resourceUrl,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[RESOURCE DELETE ERROR]', [
                'resource_url' => $resourceUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
