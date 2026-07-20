<?php

declare(strict_types=1);

namespace Keepnew\Tests\Core;

use Keepnew\Core\Config;
use Keepnew\Core\Container;
use Keepnew\Core\Kernel;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Router;
use Keepnew\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * Tests du noyau : routage (statique + paramétré), 404, accesseurs typés de la
 * requête, en-têtes de sécurité, et arithmétique monétaire en centimes.
 */
final class KernelTest extends TestCase
{
    private function makeKernel(): Kernel
    {
        $container = new Container();
        $config = new Config(['app' => ['debug' => false, 'widget_prefix' => '/widget']]);
        $router = new Router($container);

        $router->get('/health', static fn (Request $r): Response => Response::json(['status' => 'ok']));
        $router->get('/echo/{value}', static fn (Request $r): Response => Response::json(['echo' => $r->attribute('value')]));

        return new Kernel($container, $router, $config);
    }

    private function request(string $method, string $path): Request
    {
        return new Request(method: $method, path: $path);
    }

    public function testStaticRouteReturnsJson(): void
    {
        $response = $this->makeKernel()->handle($this->request('GET', '/health'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('"status":"ok"', $response->body());
    }

    public function testParameterisedRouteExtractsAttribute(): void
    {
        $response = $this->makeKernel()->handle($this->request('GET', '/echo/liege'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('"echo":"liege"', $response->body());
    }

    public function testUnknownRouteReturns404(): void
    {
        $response = $this->makeKernel()->handle($this->request('GET', '/nope'));

        self::assertSame(404, $response->status());
    }

    public function testSecurityHeadersAddedForNonWidgetRoutes(): void
    {
        $response = $this->makeKernel()->handle($this->request('GET', '/health'));

        self::assertSame('nosniff', $response->headers()['x-content-type-options'] ?? null);
        self::assertSame('SAMEORIGIN', $response->headers()['x-frame-options'] ?? null);
    }

    public function testTypedRequestAccessors(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/x',
            body: ['qty' => '3', 'name' => "  Canapé  \x00", 'accept' => 'yes'],
        );

        self::assertSame(3, $request->int('qty'));
        self::assertSame('Canapé', $request->string('name')); // trim + octet de contrôle retiré
        self::assertTrue($request->bool('accept'));
        self::assertSame(0, $request->int('missing', 0));
    }

    public function testMoneyVatIsIntegerAndRounded(): void
    {
        // 119,00 € HTVA à 21 % → 24,99 € de TVA → 143,99 € TVAC.
        self::assertSame(2499, Money::vat(11900, 2100));
        self::assertSame(14399, Money::addVat(11900, 2100));
        // Remise 10 % de 11900 = 1190.
        self::assertSame(1190, Money::percentOf(11900, 1000));
    }
}
