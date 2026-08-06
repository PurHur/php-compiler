<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmPhpCoreConstants;
use PHPUnit\Framework\TestCase;

/** PHP_OUTPUT_HANDLER_PROCESSED Core constant PROFILE gate (#28169). */
final class PhpOutputHandlerProcessedConstantTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }

    public function testPresentUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->assertTrue(CompilerVersion::supportsPhpOutputHandlerProcessedConstant());
        $v = VmPhpCoreConstants::fetchExact('PHP_OUTPUT_HANDLER_PROCESSED');
        $this->assertNotNull($v);
        $this->assertSame(16384, $v->toInt());
    }

    public function testAbsentUnderProfile82(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.2';
        $this->assertFalse(CompilerVersion::supportsPhpOutputHandlerProcessedConstant());
        $this->assertNull(VmPhpCoreConstants::fetchExact('PHP_OUTPUT_HANDLER_PROCESSED'));
    }
}
