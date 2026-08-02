<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictAdminIps;
use App\Models\AdminIpRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminIpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    // --- Model ----------------------------------------------------------

    public function test_an_empty_allowlist_allows_everyone(): void
    {
        $this->assertTrue(AdminIpRule::allows('198.51.100.7'));
    }

    public function test_a_matching_rule_allows_and_others_are_blocked(): void
    {
        AdminIpRule::create(['cidr' => '203.0.113.0/24']);

        $this->assertTrue(AdminIpRule::allows('203.0.113.42'));
        $this->assertFalse(AdminIpRule::allows('198.51.100.7'));
    }

    public function test_loopback_is_always_allowed_even_with_rules(): void
    {
        AdminIpRule::create(['cidr' => '203.0.113.0/24']);

        // The SSH-tunnel / local operator can never be shut out.
        $this->assertTrue(AdminIpRule::allows('127.0.0.1'));
        $this->assertTrue(AdminIpRule::allows('::1'));
    }

    public function test_a_single_address_rule_matches_exactly(): void
    {
        AdminIpRule::create(['cidr' => '203.0.113.5']);

        $this->assertTrue(AdminIpRule::allows('203.0.113.5'));
        $this->assertFalse(AdminIpRule::allows('203.0.113.6'));
    }

    // --- Middleware -----------------------------------------------------

    private function runMiddleware(string $ip): mixed
    {
        $request = Request::create('/admin');
        $request->server->set('REMOTE_ADDR', $ip);

        return (new RestrictAdminIps)->handle($request, fn () => response('ok'));
    }

    public function test_the_middleware_passes_an_allowed_address(): void
    {
        AdminIpRule::create(['cidr' => '203.0.113.0/24']);

        $this->assertSame('ok', $this->runMiddleware('203.0.113.9')->getContent());
    }

    public function test_the_middleware_blocks_a_disallowed_address(): void
    {
        AdminIpRule::create(['cidr' => '203.0.113.0/24']);

        $this->expectException(HttpException::class);
        $this->runMiddleware('198.51.100.9');
    }

    // --- Fail-safe command ---------------------------------------------

    public function test_the_clear_command_wipes_the_allowlist(): void
    {
        AdminIpRule::create(['cidr' => '203.0.113.0/24']);
        AdminIpRule::create(['cidr' => '198.51.100.0/24']);

        $this->artisan('vpnforge:ip-allowlist-clear')->assertSuccessful();

        $this->assertSame(0, AdminIpRule::count());
        // And with the list empty, everyone is allowed again.
        $this->assertTrue(AdminIpRule::allows('198.51.100.9'));
    }
}
