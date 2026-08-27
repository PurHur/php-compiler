<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\sodium\SodiumHex2binJitHelper;
use PHPUnit\Framework\TestCase;

/** sodium_hex2bin NestedJIT helper (#35357, php-in-php). */
final class SodiumHex2binRuntimeShrinkTest extends TestCase
{
    public function testHelperMatchesHostSodiumHex2bin(): void
    {
        if (!\function_exists('sodium_hex2bin')) {
            $this->markTestSkipped('ext-sodium required');
        }
        $this->assertSame('ab', SodiumHex2binJitHelper::hex2binArgv('6162', ''));
        $this->assertSame('ab', SodiumHex2binJitHelper::decode(
            SodiumHex2binJitHelper::stripByte('61:62', SodiumHex2binJitHelper::ignoreByte(':'))
        ));
        $this->assertSame('', SodiumHex2binJitHelper::hex2binArgv('', ''));
    }

    public function testHelperThrowsOnInvalidHex(): void
    {
        if (!\function_exists('sodium_hex2bin')) {
            $this->markTestSkipped('ext-sodium required');
        }
        $this->expectException(\SodiumException::class);
        SodiumHex2binJitHelper::decode('xyz');
    }

    public function testCallIsWiredNotLogicExceptionStub(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sodium/sodium_hex2bin.php');
        $this->assertStringContainsString('JitSodium::invokeHex2bin', $source);
        $this->assertStringNotContainsString(
            'JIT is not supported in this compiler build',
            $source
        );
        $this->assertFileExists(__DIR__.'/../../ext/sodium/SodiumHex2binJitHelper.php');
    }
}
