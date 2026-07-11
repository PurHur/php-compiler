<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getenv embed + standalone AOT route through GetenvJitHelper PHP (#9092, #13194, #13621). */
final class GetenvJitRuntimeShrinkTest extends TestCase
{
    public function testStringGetenvRoutesThroughGetenvJitHelperNotLibcBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('GetenvJitHelper', $source);
        $this->assertStringContainsString('implementGetenvBridge', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringGetenvLibcBridge.php');
        $this->assertStringNotContainsString('StringGetenvLibcBridge', $source);
    }
}
