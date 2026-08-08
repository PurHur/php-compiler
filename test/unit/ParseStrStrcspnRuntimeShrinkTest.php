<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * parse_str AOT kernel must use PHP-owned __compiler_strcspn, not libc (#29050).
 */
final class ParseStrStrcspnRuntimeShrinkTest extends TestCase
{
    public function testParseStrKernelUsesCompilerStrcspnNotLibc(): void
    {
        $source = (string) file_get_contents(
            __DIR__.'/../../ext/standard/JitParseStrUserScriptCstrKernel.php'
        );
        $this->assertStringContainsString("lookupFunction('__compiler_strcspn')", $source);
        $this->assertStringNotContainsString("lookupFunction('strcspn')", $source);
        $this->assertStringContainsString('StringStrspn::ensureLinked', $source);
        $this->assertStringContainsString('#29050', $source);
    }

    public function testLibcExternDropsStrcspnDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'strcspn' =>", $source);
        $this->assertStringContainsString('#29050', $source);
    }
}
