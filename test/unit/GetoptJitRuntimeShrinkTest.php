<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\GetoptEngine;
use PHPCompiler\ext\standard\GetoptJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * getopt JIT routes through GetoptJitHelper PHP; NestedJIT via JitVmHelperLink (#3251, #26213).
 */
final class GetoptJitRuntimeShrinkTest extends TestCase
{
    public function testGetoptJitHelperDelegatesToGetoptEngine(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/GetoptJitHelper.php');
        $this->assertStringContainsString('GetoptEngine::parse', $source);
    }

    public function testGetoptRoutesThroughJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Getopt.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('/ext/standard/GetoptJitHelper.php', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertLessThan(60, \substr_count($source, "\n") + 1);
    }

    public function testJitGetoptRoutesThroughGetoptBuiltin(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitGetopt.php');
        $this->assertStringContainsString('Getopt::ensureLinked', $source);
        $this->assertStringContainsString('Getopt::helperFunction', $source);
    }

    public function testGetoptJitHelperSemanticsMatchEngine(): void
    {
        $argv = ['prog', '-a', 'val'];
        $this->assertSame(
            GetoptEngine::parse($argv, 'a:', []),
            GetoptJitHelper::parse('a:', [], $argv)
        );
        $this->assertSame(
            GetoptEngine::parse(['prog'], 'a', []),
            GetoptJitHelper::parse('a', [], ['prog'])
        );
    }
}
