<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmLocaleCollate;
use PHPUnit\Framework\TestCase;

/** strcoll/strxfrm without libc FFI (#13566, php-in-php). */
final class VmLocaleCollateRuntimeShrinkTest extends TestCase
{
    public function testVmLocaleCollateHasNoLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmLocaleCollate.php');
        $this->assertStringContainsString('strcoll', $source);
        $this->assertStringContainsString('strxfrm', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $this->assertStringNotContainsString('libc.so', $source);
    }

    public function testStrcollMatchesHostBuiltin(): void
    {
        if (!\function_exists('strcoll')) {
            $this->markTestSkipped('strcoll unavailable');
        }

        $this->assertSame(\strcoll('a', 'b'), VmLocaleCollate::strcoll('a', 'b'));
        $this->assertSame(\strcoll('b', 'a'), VmLocaleCollate::strcoll('b', 'a'));
    }

    public function testStrxfrmMatchesHostBuiltin(): void
    {
        if (!\function_exists('strxfrm')) {
            $this->markTestSkipped('strxfrm unavailable');
        }

        $this->assertSame(\strxfrm('café'), VmLocaleCollate::strxfrm('café'));
    }
}
