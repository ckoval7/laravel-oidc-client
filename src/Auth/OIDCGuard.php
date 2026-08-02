<?php

/** @noinspection InterfacesAsConstructorDependenciesInspection */

namespace Maicol07\OIDCClient\Auth;

use Exception;
use Illuminate\Auth\SessionGuard;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Maicol07\OIDCClient\Events\LoginWithOIDC;
use Maicol07\OpenIDConnect\Client;
use Maicol07\OpenIDConnect\ClientAuthMethod;
use Maicol07\OpenIDConnect\CodeChallengeMethod;
use Maicol07\OpenIDConnect\JwtSigningAlgorithm;
use Maicol07\OpenIDConnect\MutualTlsCertificate;
use Maicol07\OpenIDConnect\ResponseType;
use Maicol07\OpenIDConnect\Scope;
use Maicol07\OpenIDConnect\UserInfo;

class OIDCGuard extends SessionGuard
{
    private Client $oidc;

    /** @var Dispatcher|null @phpstan-ignore-next-line */
    protected $events;

    /**
     * @throws BindingResolutionException
     * @throws ConnectionException
     */
    public function __construct(
        $name,
        OIDCUserProvider $provider,
        Session $session,
        private readonly Application $app,
        ?Request $request = null,
    ) {
        parent::__construct($name, $provider, $session, $request);
        $this->buildOIDCClient();
    }

    /**
     * @throws BindingResolutionException
     * @throws ConnectionException
     */
    public function buildOIDCClient(): void
    {
        $config = $this->app->make('config')->get('oidc');
        $this->oidc = new Client(
            ...$this->mutualTlsOptions($config),
            client_id: $config['client_id'],
            client_secret: $config['client_secret'],
            provider_url: $config['provider_url'],
            issuer: $config['issuer'],
            scopes: array_map(static fn (string $scope): Scope => Scope::from($scope), array_filter($config['scopes'])),
            redirect_uri: route(config('oidc.routes.name').'callback'),
            enable_pkce: $config['enable_pkce'],
            enable_nonce: $config['enable_nonce'],
            code_challenge_method: CodeChallengeMethod::from($config['code_challenge_method']),
            time_drift: $config['time_drift'],
            response_types: array_map(static fn (string $type): ResponseType => ResponseType::from($type), array_filter($config['response_types'])),
            id_token_signing_alg_values_supported: array_map(static fn (string $alg): JwtSigningAlgorithm => JwtSigningAlgorithm::fromName($alg), array_filter($config['id_token_signing_alg_values_supported'])),
            authorization_endpoint: $config['authorization_endpoint'],
            token_endpoint: $config['token_endpoint'],
            userinfo_endpoint: $config['userinfo_endpoint'],
            end_session_endpoint: $config['end_session_endpoint'],
            registration_endpoint: $config['registration_endpoint'],
            introspect_endpoint: $config['introspect_endpoint'],
            revocation_endpoint: $config['revocation_endpoint'],
            jwks_endpoint: $config['jwks_endpoint'],
            jwt_audience: $config['jwt_audience'],
            authorization_response_iss_parameter_supported: $config['authorization_response_iss_parameter_supported'],
            token_endpoint_auth_methods_supported: array_map(static fn (string $method): ClientAuthMethod => ClientAuthMethod::from($method), array_filter($config['token_endpoint_auth_methods_supported'])),
            http_proxy: $config['http_proxy'],
            cert_path: $config['cert_path'],
            verify_ssl: $config['verify'],
            timeout: $config['timeout'],
            client_name: $config['client_name'],
            allow_implicit_flow: $config['allow_implicit_flow'],
            jwks: $config['jwks']
        );
    }

    /**
     * Mutual-TLS constructor arguments (RFC 8705), spread into the client.
     *
     * These are only understood by a client supporting RFC 8705, so nothing is passed unless mutual
     * TLS is actually configured: an upstream client without those parameters keeps working, and
     * only a deployment that opted in needs the fork.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function mutualTlsOptions(array $config): array
    {
        $certificate = $this->buildMutualTlsCertificate($config);
        $auth_method = data_get($config, 'token_endpoint_auth_method');
        $bound_tokens = (bool) data_get($config, 'tls_client_certificate_bound_access_tokens', false);

        if ($certificate === null && blank($auth_method) && !$bound_tokens) {
            return [];
        }

        return array_filter([
            'mtls_certificate' => $certificate,
            'token_endpoint_auth_method' => blank($auth_method) ? null : ClientAuthMethod::from($auth_method),
            'tls_client_certificate_bound_access_tokens' => $bound_tokens,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Builds the client certificate presented during the TLS handshake for mutual-TLS client
     * authentication and certificate-bound access tokens (RFC 8705).
     *
     * Returns null when no certificate is configured, so that clients authenticating with a
     * secret are left untouched.
     *
     * @param array<string, mixed> $config
     */
    private function buildMutualTlsCertificate(array $config): ?MutualTlsCertificate
    {
        $certificate_path = data_get($config, 'mtls.certificate_path');
        if (blank($certificate_path)) {
            return null;
        }

        return new MutualTlsCertificate(
            certificate_path: $certificate_path,
            private_key_path: data_get($config, 'mtls.private_key_path') ?: null,
            passphrase: data_get($config, 'mtls.passphrase') ?: null
        );
    }

    /**
     * @throws Exception
     */
    final public function getAuthorizationUrl(): string
    {
        return $this->oidc->getAuthorizationUrl(config('oidc.authorization_endpoint_query_params'), csrf_token());
    }

    /**
     * @throws Exception
     */
    final public function getUserInfo(): UserInfo
    {
        $this->oidc->authenticate();

        return $this->oidc->getUserInfo();
    }

    /**
     * @throws Exception
     */
    final public function generateUser(?UserInfo $user_info = null): User
    {
        if ($user_info === null) {
            $user_info = $this->getUserInfo();
        }

        assert($this->provider instanceof OIDCUserProvider);

        return $this->provider->retrieveByInfo($this->oidc->issuer ?? $this->oidc->provider_url, $user_info);
    }

    protected function fireLoginEvent($user, $remember = false): void
    {
        $this->events?->dispatch(new LoginWithOIDC($this->name, $user, $remember, $user->oidcUserInfo ?? $this->getUserInfo()));
    }
}
