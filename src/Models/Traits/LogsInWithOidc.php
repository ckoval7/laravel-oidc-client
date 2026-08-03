<?php

namespace Maicol07\OIDCClient\Models\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Maicol07\OIDCClient\Models\OidcAuthMapping;
use Maicol07\OpenIDConnect\UserInfo;

trait LogsInWithOidc
{
    public function oidcAuthMappings(): HasMany
    {
        return $this->hasMany(OidcAuthMapping::class);
    }

    /**
     * Map OIDC UserInfo attributes to User model attributes.
     *
     * This method can be overridden in the User model to customize the mapping.
     *
     * @param  string  $issuer  The OIDC issuer.
     * @param  UserInfo  $user_info  The OIDC UserInfo object.
     * @param  OidcAuthMapping  $mapping  The OIDC Auth Mapping instance.
     */
    public function mapOIDCUserinfo(string $issuer, UserInfo $user_info, OidcAuthMapping $mapping): void
    {
        // The default must not be passed to config(): config() resolves a closure default by
        // *calling* it (Arr::get -> value()), with no arguments, so a callable default is an
        // ArgumentCountError rather than a fallback. Read the key, then fall back explicitly.
        $attributes = config('oidc.user_creation_attributes'); // TODO: Remove in next major release

        $attributes ??= static fn (string $issuer, UserInfo $user_info): array => [
            'first_name' => $user_info->given_name,
            'last_name' => $user_info->family_name,
        ];

        // The setting is documented as a callable, but its name reads like a plain map, so a
        // literal array is accepted too rather than fataling on a non-callable value.
        if (is_callable($attributes)) {
            $attributes = $attributes($issuer, $user_info, $mapping);
        }

        // Whatever produced it, this is already the attribute array; wrapping it in another
        // array would hand fill() a single nested element instead of the attributes.
        $this->fill($attributes);
    }
}
