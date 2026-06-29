<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getenv standalone AOT uses libc bridge; embed uses GetenvJitHelper (#9092, #13571). */
final class GetenvJitRuntimeShrinkTest extends TestCase
{
    public function testStringGetenvRoutesStandaloneThroughLibcBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('GetenvJitHelper', $source);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringGetenvLibcBridge.php');
        $this->assertStringContainsString('StringGetenvLibcBridge', $source);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
    }
}
