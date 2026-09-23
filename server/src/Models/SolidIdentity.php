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
    protected $fillable = ['company_uuid', 'user_uuid', 'token_response', 'identifier'];

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
        $accessToken = data_get($this, 'token_response.access_token');

        return is_string($accessToken) && $accessToken !== '' ? $accessToken : null;
    }

    /**
     * The refresh token from the last successful sign-in, if the provider issued one.
     */
    public function getRefreshToken(): ?string
    {
        $refreshToken = data_get($this, 'token_response.refresh_token');

        return is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null;
    }

    /**
     * Persist a token response together with the ID token claims that were
     * verified when it was issued.
     *
     * The claims are kept because an ID token cannot be re-verified later — it is
     * short-lived, and by the time anything reads the WebID back out of the
     * database the token has expired. Storing what was verified at sign-in is what
     * lets `getWebId()` avoid trusting an unverified decode.
     *
     * @param array<string, mixed> $verifiedIdTokenClaims
     */
    public function storeTokenResponse(object $tokenResponse, array $verifiedIdTokenClaims = []): self
    {
        $payload = (array) $tokenResponse;

        if ($verifiedIdTokenClaims !== []) {
            $payload['id_token_claims'] = $verifiedIdTokenClaims;
        }

        $this->update(['token_response' => $payload]);

        return $this;
    }

    /**
     * The user's WebID.
     *
     * Read from the claims verified at sign-in. Falls back to an *unverified*
     * decode of the stored ID token for identities that authenticated before those
     * claims were persisted, so an existing session is not signed out by the
     * upgrade; that fallback can be removed once those have all been renewed.
     */
    public function getWebId(): ?string
    {
        $webId = data_get($this, 'token_response.id_token_claims.webid')
            ?? data_get($this, 'token_response.id_token_claims.sub');

        if (is_string($webId) && $webId !== '') {
            return $webId;
        }

        $idToken = data_get($this, 'token_response.id_token');

        if (!is_string($idToken) || $idToken === '') {
            return null;
        }

        // Decoded in place rather than through the OIDC client: the client's
        // constructor performs provider discovery, and a model accessor must not
        // make a network call. Nothing is verified here — see the note above.
        $segments = explode('.', $idToken);
        $claims   = isset($segments[1])
            ? json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true)
            : null;

        if (!is_array($claims)) {
            return null;
        }

        $webId = $claims['webid'] ?? $claims['sub'] ?? null;

        return is_string($webId) && $webId !== '' ? $webId : null;
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
