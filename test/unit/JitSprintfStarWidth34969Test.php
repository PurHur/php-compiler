<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\standard\JitSprintf;
use PHPUnit\Framework\TestCase;

/**
 * JitSprintf star-width argv alignment for libc snprintf (#34969).
 */
final class JitSprintfStarWidth34969Test extends TestCase
{
    public function testConversionSpecifiersEmitStarSlots(): void
    {
        $m = new \ReflectionMethod(JitSprintf::class, 'conversionSpecifiers');
        $m->setAccessible(true);

        self::assertSame(['*', 's'], $m->invoke(null, '%*s'));
        self::assertSame(['*', 's'], $m->invoke(null, '%.*s'));
        self::assertSame(['*', '*', 's'], $m->invoke(null, '%*.*s'));
        self::assertSame(['*', 'd'], $m->invoke(null, '%*d'));
        self::assertSame(['*', 'd'], $m->invoke(null, '%0*d'));
        self::assertSame(['s'], $m->invoke(null, '%5s'));
        self::assertSame(['*', 's'], $m->invoke(null, "%*s\n"));
    }
}
