<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStreamBlockingNative;
use PHPUnit\Framework\TestCase;

/** VM stream_set_blocking via libc fcntl — fd streams avoid host stream_set_blocking (#6007, #7908). */
final class VmStreamBlockingNativeRuntimeShrinkTest extends TestCase
{
    public function testVmStreamBlockingNativeUsesFcntl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamBlockingNative.php');
        $this->assertStringContainsString('fcntl', $source);
    }

    public function testVmStreamMetaDoesNotCallHostStreamSetBlocking(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamMeta.php');
        $this->assertStringNotContainsString('\\stream_set_blocking(', $source);
    }

    public function testVmFsStreamSetBlockingRoutesFdStreams(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmStreamBlockingNative::setBlocking', $source);
        $this->assertStringContainsString('socketFdForHandle', $source);
    }
}
