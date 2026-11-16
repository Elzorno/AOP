<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrustedProxyHttpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_trusted_tunnel_https_header_is_accepted_and_emits_transport_security(): void
    {
        config()->set('aop.trusted_proxies', ['192.168.1.167']);
        config()->set('aop.enforce_https', true);
        config()->set('session.secure', true);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.167'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/login');

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertTrue($cookie->isSecure(), $cookie->getName().' must use Secure through the tunnel.');
        }
    }

    public function test_untrusted_client_cannot_spoof_forwarded_https(): void
    {
        config()->set('aop.trusted_proxies', ['192.168.1.167']);
        config()->set('aop.enforce_https', true);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.99'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/login');

        $response->assertStatus(308);
        $this->assertStringStartsWith('https://', $response->headers->get('Location'));
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_https_enforcement_generates_secure_application_redirects(): void
    {
        config()->set('aop.trusted_proxies', ['192.168.1.167']);
        config()->set('aop.enforce_https', true);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '192.168.1.167',
            'HTTP_HOST' => '127.0.0.1:8081',
        ])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/');

        $response->assertRedirect('https://127.0.0.1:8081/login');
    }
}
