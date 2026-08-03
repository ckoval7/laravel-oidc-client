<?php

namespace Maicol07\OIDCClient\Tests;

use Maicol07\OIDCClient\Auth\OIDCGuard;
use Maicol07\OIDCClient\OIDCServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Covers how mutual-TLS (RFC 8705) config is turned into client constructor arguments.
 *
 * The guard cannot be constructed here: its constructor builds a Client, which performs OIDC
 * auto-discovery over the network. The units under test are pure functions of the config array,
 * so they are invoked directly on an uninitialised instance.
 */
final class MutualTlsConfigurationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [OIDCServiceProvider::class];
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $guard = (new ReflectionClass(OIDCGuard::class))->newInstanceWithoutConstructor();

        $reflected = new ReflectionMethod(OIDCGuard::class, $method);
        $reflected->setAccessible(true);

        return $reflected->invoke($guard, ...$arguments);
    }

    /** @return array<string, mixed> */
    private function resolveOptions(array $config): array
    {
        return $this->invoke('mutualTlsOptions', $config);
    }

    private function supported(): bool
    {
        return $this->invoke('mutualTlsSupported');
    }

    public function test_nothing_is_passed_when_mutual_tls_is_not_configured(): void
    {
        $this->assertSame([], $this->resolveOptions([]));
    }

    public function test_falsy_configuration_does_not_enable_mutual_tls(): void
    {
        $config = [
            'mtls' => ['certificate_path' => null],
            'token_endpoint_auth_method' => '',
            'tls_client_certificate_bound_access_tokens' => false,
        ];

        $this->assertSame([], $this->resolveOptions($config));
    }

    /**
     * A cached config (`config:cache`) serialises env booleans to strings. Reading "false" as true
     * would silently request certificate-bound tokens nobody asked for.
     */
    public function test_string_false_does_not_enable_mutual_tls(): void
    {
        $config = ['tls_client_certificate_bound_access_tokens' => 'false'];

        $this->assertSame([], $this->resolveOptions($config));
    }

    public function test_string_true_enables_mutual_tls(): void
    {
        $config = ['tls_client_certificate_bound_access_tokens' => 'true'];

        if (!$this->supported()) {
            $this->expectException(RuntimeException::class);
            $this->resolveOptions($config);

            return;
        }

        $this->assertTrue($this->resolveOptions($config)['tls_client_certificate_bound_access_tokens']);
    }

    /**
     * Each of these is an independent trigger, and each one alone would reach the client. On a
     * client without RFC 8705 support that is an unrecoverable "Unknown named parameter" error,
     * so it has to surface as an explicit exception instead.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function mutualTlsConfigurations(): array
    {
        return [
            'certificate only' => [['mtls' => ['certificate_path' => '/tmp/client.crt']]],
            'auth method only' => [['token_endpoint_auth_method' => 'tls_client_auth']],
            'bound tokens only' => [['tls_client_certificate_bound_access_tokens' => true]],
        ];
    }

    #[DataProvider('mutualTlsConfigurations')]
    public function test_configured_mutual_tls_is_rejected_when_the_client_lacks_support(array $config): void
    {
        if ($this->supported()) {
            $this->markTestSkipped('Installed client supports RFC 8705.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not support it/');

        $this->resolveOptions($config);
    }

    #[DataProvider('mutualTlsConfigurations')]
    public function test_configured_mutual_tls_reaches_the_client_when_supported(array $config): void
    {
        if (!$this->supported()) {
            $this->markTestSkipped('Installed client does not support RFC 8705.');
        }

        $this->assertNotSame([], $this->resolveOptions($config));
    }

    public function test_no_certificate_is_built_without_a_configured_path(): void
    {
        $this->assertNull($this->invoke('buildMutualTlsCertificate', []));
    }
}
