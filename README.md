# OIDC Client

A Laravel package for delegating authentication to an OpenID Provider.

> This package is an heavenly modified fork of [cabinetoffice / oidc-client — Bitbucket](https://bitbucket.org/cabinetoffice/oidc-client)

## Requirements

- PHP 8.3+
- Laravel 11+
- Composer 2

## Installation

Begin by adding this package to your depedencies with the command:

```powershell
composer require maicol07/laravel-oidc-client
```

If you have opted out from auto discovery, you'll need to add the following line to the list of registered service
providers in `config/app.php`:

```php
Maicol07\OIDCClient\OIDCServiceProvider::class
```

Edit your `config/auth.php` file to use OpenID as the authentication method for your users:

```php
'guards' => [
    'web' => [
        'driver' => 'oidc',
        ...
    ],
    ...
],
```

## Configuration

You can set the following environment variables to adjust the package settings:

- `OIDC_CLIENT_ID`: Client ID of your app. This is commonly provided by your OIDC provider.
- `OIDC_CLIENT_SECRET`: Client secret of your app. This is commonly provided by your OIDC provider.
- `OIDC_PROVIDER_URL`: URL of your OIDC provider. This is used if your provider supports OIDC Auto Discovery.
- `OIDC_CALLBACK_ROUTE`: A path (with or without leading slash) to append to the provider name, to make the
  callback route path. Defaults to `callback`
  Example with the default values: `oidc/callback` (`oidc/` + `OIDC_CALLBACK_ROUTE_PATH`)
- `OIDC_VERIFY`: Verify SSL when sending requests to the server. Defaults to `true`. (Optional: You can
  set `OIDC_CERT_PATH` to an SSL certificate path if you set this option to `false`)
- `OIDC_HTTP_PROXY`: If you have a proxy, set it here.
- `OIDC_SCOPES`: A list of scopes, separated by a space (` `). Defaults to `['openid']`.
  Example of valid value: `openid email`
- `OIDC_AUTHORIZATION_ENDPOINT_QUERY_PARAMS`: A list of query parameters to add to the authorization endpoint encoded as
  a JSON object.
  Example of valid value: `{"response_type":"code"}`
- `OIDC_DISABLE_STATE_MIDDLEWARE_FOR_POST_CALLBACK`: A boolean to disable the registration of the `OIDCStateMiddleware` middleware.  
  This middleware rebuilds the session token held in the `state` parameter of a `POST` request to the `callback` route.

You can find other options to set and their env variables in `config/oidc.php`. Note that some options are not
required (like endpoints) if you use OIDC auto discovery!

### Mutual TLS (RFC 8705)

The client can authenticate with a certificate presented during the TLS handshake instead of a client secret, and
receive certificate-bound access tokens.

> **This requires a client supporting [RFC 8705](https://tools.ietf.org/html/rfc8705).** Mutual TLS support is not
> yet part of an upstream `maicol07/oidc-client` release, so the underlying client has to be pulled from a fork.
> Add the following to your **application's** `composer.json` (the inline `as 4.99.0` alias is what satisfies this
> package's `^4` constraint), then run `composer update maicol07/oidc-client`:
>
> ```json
> "repositories": [
>     { "type": "vcs", "url": "https://github.com/ckoval7/oidc-client-php" }
> ],
> "require": {
>     "maicol07/oidc-client": "dev-feat/rfc8705-mutual-tls as 4.99.0"
> }
> ```
>
> Support is detected from the installed client, so this package works with or without it: configuring any of the
> options below when the client does not support RFC 8705 throws a `RuntimeException` explaining what to install,
> rather than failing deeper in the stack. Leaving them unset is unaffected either way.

- `OIDC_MTLS_CERTIFICATE_PATH`: Path to the PEM encoded client certificate. Leave every option below unset and the
  package behaves exactly as before.
- `OIDC_MTLS_PRIVATE_KEY_PATH`: Path to the PEM encoded private key. Can be omitted if the key is bundled in the
  certificate file.
- `OIDC_MTLS_PASSPHRASE`: Passphrase of the private key, if it is encrypted.
- `OIDC_TOKEN_ENDPOINT_AUTH_METHOD`: Client authentication method for the token endpoint, e.g. `tls_client_auth`
  (certificate issued by a CA the provider trusts) or `self_signed_tls_client_auth` (the provider holds the
  certificate itself). If left unset, a mutual-TLS method announced by the provider is selected only when a
  certificate is configured.
- `OIDC_TLS_CLIENT_CERTIFICATE_BOUND_ACCESS_TOKENS`: Request certificate-bound access tokens. Defaults to `false`,
  and is picked up automatically from the provider's discovery document.

`OIDC_CLIENT_SECRET` can be left unset with mutual TLS — the certificate authenticates the client, and the secret is
not sent to the token endpoint. When the provider publishes
[`mtls_endpoint_aliases`](https://tools.ietf.org/html/rfc8705#section-5), those endpoints are used automatically.

Note that the certificate and its private key are read by the web server user (PHP-FPM, Octane), not by the user
running the deploy, so make sure they are readable by it.

You can also publish the config file (`config/oidc.php`) if you want:

```powershell
php artisan vendor:publish --provider="Maicol07\OIDCClient\OIDCServiceProvider"
```

## How to use

Once everything is set up, you can replace your login system with a call to the route `route('oidc.login')`. For
logouts, use the route `route('oidc.logout')`.

You can set the following environment variables to specify the routes/URLs you want your users to be redirected to upon
successful authentication/logout: `OIDC_REDIRECT_PATH_AFTER_LOGIN` and `OIDC_REDIRECT_PATH_AFTER_LOGOUT`.

You should add the `Maicol07\OIDCClient\Models\Traits\LogsInWithOidc` to your `User` model if you want to use the
get the mapping relation.

### Customizing user mappings
You can customize how the user information received from the OIDC provider is mapped to your `User` model by
overriding the `mapOIDCUserinfo` method from the `LogsInWithOidc` trait in your `User` model.
Here's an example of how to do this:

```php
use Maicol07\OIDCClient\Models\Traits\LogsInWithOidc;
use Maicol07\OIDCClient\Models\OIDCUserinfo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, LogsInWithOidc;

    // Other model properties and methods...

    /**
     * Map OIDC UserInfo attributes to User model attributes.
     * 
     * This method can be overridden in the User model to customize the mapping.
     * 
     * @param string $issuer The OIDC issuer.
     * @param UserInfo $user_info The OIDC UserInfo object.
     * @param OidcAuthMapping $mapping The OIDC Auth Mapping instance.
     */
    public function mapOIDCUserinfo(string $issuer, UserInfo $user_info, OidcAuthMapping $mapping): void
    {
        // Custom mapping logic here
        $this->name = $user_info->get('name', $this->name);
        $this->email = $user_info->get('email', $this->email);
        // Add more mappings as needed
    }
}
```

---

> Originally developed by Cabinet Office Digital Development in October 2019.
>
> Currently maintained by [maicol07](https://maicol07.it) from October 2021
