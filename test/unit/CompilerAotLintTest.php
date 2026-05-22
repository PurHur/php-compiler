<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint gate for lib/Compiler.php (php-types array shape PHPDoc).
 */
final class CompilerAotLintTest extends TestCase
{
    public function testLibCompilerParseAndCompile(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/lib/Compiler.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }
}
