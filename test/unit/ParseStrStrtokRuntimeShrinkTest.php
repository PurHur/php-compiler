<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * parse_str AOT kernel must use PHP-owned __compiler_strtok_r, not libc (#29091).
 */
final class ParseStrStrtokRuntimeShrinkTest extends TestCase
{
    public function testParseStrKernelUsesCompilerStrtokRNotLibc(): void
    {
        $source = (string) file_get_contents(
            __DIR__.'/../../ext/standard/JitParseStrUserScriptCstrKernel.php'
        );
        $this->assertStringContainsString("lookupFunction('__compiler_strtok_r')", $source);
        $this->assertStringContainsString('emitCompilerStrtokR', $source);
        $this->assertStringContainsString('#29091', $source);
        $this->assertStringNotContainsString("lookupFunction('strtok_r')", $source);
        $this->assertStringNotContainsString("'strtok_r'", $source);
    }

    public function testLibcExternDropsStrtokRDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'strtok_r' =>", $source);
        $this->assertStringContainsString('#29091', $source);
    }
}
