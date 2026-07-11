<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStatNative;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** Issue #4555 / #12265: VM realpath() via VmStatPure, not libc FFI. */
final class VmRealpathNativeTest extends TestCase
{
    public function testVmStringRealpathUsesStatNativeWhenAvailable(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/VmString.php');
        $this->assertStringContainsString('VmStatNative::realpath', $src);
        $this->assertStringContainsString('VmGetcwdNative::resolve', $src);
    }

    public function testRealpathDotIsAbsolute(): void
    {
        $resolved = VmString::realpath('.');
        $this->assertIsString($resolved);
        $this->assertNotSame('.', $resolved);
        $this->assertSame('/', $resolved[0]);

        if (VmStatNative::available()) {
            $this->assertSame(VmStatNative::realpath('.'), $resolved);
        }
    }

    public function testRealpathMissingReturnsFalse(): void
    {
        $this->assertFalse(VmString::realpath('/tmp/no-such-entry-phpc-realpath-unit-'.(string) getmypid()));
    }

    public function testRealpathEmptyStringReturnsFalse(): void
    {
        $this->assertFalse(VmString::realpath(''));
    }
}
