<?php

declare(strict_types=1);

namespace Keepnew\Tests\Notification;

use Keepnew\Notification\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    public function testRendersDottedVariables(): void
    {
        $r = new TemplateRenderer();
        $out = $r->render(
            'Bonjour {{customer.first_name}}, votre demande {{booking.reference}}.',
            ['customer' => ['first_name' => 'Marie'], 'booking' => ['reference' => 'KN-2026-000001']],
        );
        self::assertSame('Bonjour Marie, votre demande KN-2026-000001.', $out);
    }

    public function testMissingVariableBecomesEmpty(): void
    {
        $r = new TemplateRenderer();
        self::assertSame('Salut .', $r->render('Salut {{customer.nope}}.', ['customer' => []]));
    }

    public function testEscapesWhenRequested(): void
    {
        $r = new TemplateRenderer();
        $out = $r->render('{{x}}', ['x' => '<script>'], true);
        self::assertSame('&lt;script&gt;', $out);
        // Sans échappement (SMS) : valeur brute.
        self::assertSame('<script>', $r->render('{{x}}', ['x' => '<script>'], false));
    }
}
