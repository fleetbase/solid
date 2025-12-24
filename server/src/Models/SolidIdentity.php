<?php

namespace Fleetbase\Solid\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Company;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Support\Utils;
use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Str;

class SolidIdentity extends Model
{
    use HasUuid;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'solid_identities';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['company_uuid', 'user_uuid', 'token_response', 'identifier', 'css_email', 'css_password', 'css_client_id', 'css_client_secret', 'css_client_resource_url'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'token_response' => Json::class,
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    public function getAccessToken(): ?string
    {
        // Prefer user's OIDC token (has proper permissions for user's pod)
        $oidcToken = data_get($this, 'token_response.access_token');
        
        if ($oidcToken) {
            \Illuminate\Support\Facades\Log::info('[USING OIDC TOKEN]', ['has_token' => true]);
            return $oidcToken;
        }
        
        \Illuminate\Support\Facades\Log::debug('[GET ACCESS TOKEN - NO OIDC]', [
            'has_css_client_id' => !empty($this->css_client_id),
            'has_css_client_secret' => !empty($this->css_client_secret),
        ]);
        
        // Fallback to CSS client credentials (for automation/service accounts)
        if ($this->css_client_id && $this->css_client_secret) {
            \Illuminate\Support\Facades\Log::info('[ATTEMPTING CSS TOKEN]', ['client_id' => substr($this->css_client_id, 0, 10) . '...']);
            try {
                $cssAccountService = app(\Fleetbase\Solid\Services\CssAccountService::class);
                $oidcClient = app(\Fleetbase\Solid\Client\OpenIDConnectClient::class, ['options' => ['identity' => $this]]);
                
                // Get issuer from token response or use CSS server URL
                $issuer = data_get($this, 'token_response.iss') 
                    ?? data_get($this, 'token_response.issuer')
                    ?? 'http://solid:3000/';  // Default CSS server
                $clientId = $this->css_client_id;  // Not encrypted
                $clientSecret = decrypt($this->css_client_secret);  // Encrypted
                
                $cssToken = $cssAccountService->getAccessToken($issuer, $clientId, $clientSecret, $oidcClient);
                
                if ($cssToken) {
                    \Illuminate\Support\Facades\Log::info('[USING CSS TOKEN]', ['has_token' => true]);
                    return $cssToken;
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('[CSS TOKEN FAILED]', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'has_client_id' => !empty($this->css_client_id),
                    'has_client_secret' => !empty($this->css_client_secret),
                ]);
            }
        }
        
        // No token available
        \Illuminate\Support\Facades\Log::warning('[NO ACCESS TOKEN AVAILABLE]');
        return null;
    }

    public function getRedirectUri(array $query = [], int $port = 8000): string
    {
        return Utils::apiUrl('solid/int/v1/oidc/complete-registration/' . $this->identifier, $query, $port);
    }

    public function generateRequestCode(): SolidIdentity
    {
        $requestCode   = static::generateUniqueRequestCode();
        $this->update(['identifier' => $requestCode]);

        return $this;
    }

    /**
     * Generate a unique request code.
     */
    public static function generateUniqueRequestCode(): string
    {
        do {
            // Generate a random string.
            $requestCode = Str::random(16);

            // Check if it's unique in the identifier column
            $exists = static::where('identifier', $requestCode)->exists();
        } while ($exists);

        return $requestCode;
    }

    /**
     * Initializes a SolidIdentity instance for the current session.
     *
     * This method retrieves an existing SolidIdentity based on the user's and company's UUIDs
     * from the current session. If no identity is found, a new one is created.
     * In both cases, a new unique request code is generated and updated for the SolidIdentity.
     * The updated SolidIdentity instance is then returned.
     *
     * @return SolidIdentity the SolidIdentity instance with updated request code
     */
    public static function initialize(): SolidIdentity
    {
        $requestCode   = static::generateUniqueRequestCode();
        $solidIdentity = static::firstOrCreate(['user_uuid' => session('user'), 'company_uuid' => session('company')]);
        $solidIdentity->update(['identifier' => $requestCode]);

        return $solidIdentity;
    }

    public static function current(): SolidIdentity
    {
        $solidIdentity = static::where(['user_uuid' => session('user'), 'company_uuid' => session('company')])->first();
        if (!$solidIdentity) {
            return static::initialize();
        }

        // If no request code
        if (empty($solidIdentity->identifier)) {
            $solidIdentity->generateRequestCode();
        }

        return $solidIdentity;
    }

    public function request(string $method, string $uri, string|array $data = [], array $options = [])
    {
        $solidClient = new SolidClient(['identity' => $this]);

        return $solidClient->requestWithIdentity($this, $method, $uri, $data, $options);
    }
}
