<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * User-script floatval()/is_numeric() stay PHP-in-PHP via ext/standard (#31997).
 * Libc strtod(3) is module-local after the LibcExtern always-on drop.
 */
final class StrtodRuntimeShrinkTest extends TestCase
{
    public function testUserScriptFloatvalUsesPhpNotLibcOnly(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/floatval.php');
        $this->assertStringContainsString('LibcExtern::ensureStrtodDecl', $builtin);
        $this->assertStringContainsString('#31997', $builtin);
    }

    public function testLibcExternDropsAlwaysOnStrtod(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'strtod' =>", $source);
        $this->assertStringContainsString('ensureStrtodDecl', $source);
        $this->assertStringContainsString('#31997', $source);
    }

    public function testScalarCastRoutesStrtodThroughEnsureDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitZendScalarCast.php');
        $this->assertStringContainsString('LibcExtern::ensureStrtodDecl', $source);
        $this->assertStringContainsString('#31997', $source);
    }
}
