<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint gate for lib/OpCode.php class constants (issue #84).
 */
final class OpCodeAotLintTest extends TestCase
{
    public function testLibOpCodeParseAndCompile(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/lib/OpCode.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }
}
