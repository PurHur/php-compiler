<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SuperglobalNameJitHelper;
use PHPCompiler\ext\standard\SuperglobalNames;
use PHPUnit\Framework\TestCase;

/**
 * SuperglobalNameRuntime routes through SuperglobalNameJitHelper PHP via
 * JitVmHelperLink::ensureCompiled (#9271 / #25091 / peer #25042).
 */
final class SuperglobalNameRuntimeShrinkTest extends TestCase
{
    public function testSuperglobalNameRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalNameRuntime.php');
        $this->assertStringContainsString('SuperglobalNameJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString("lookupFunction('memcmp')", $source);
        $this->assertStringNotContainsString('identicalToAsciiLiteral', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }

    public function testSuperglobalNameJitHelperDelegatesToSuperglobalNames(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SuperglobalNameJitHelper.php');
        $this->assertStringContainsString('SuperglobalNames::isSuperglobalName', $source);
    }

    public function testSuperglobalNameJitHelperSemanticsMatchSuperglobalNames(): void
    {
        foreach (['_GET', '_POST', '_SERVER', '_ENV', '_COOKIE', '_FILES', '_REQUEST', 'GLOBALS', '_SESSION'] as $name) {
            $this->assertSame(
                SuperglobalNames::isSuperglobalName($name) ? 1 : 0,
                SuperglobalNameJitHelper::isSuperglobalName($name),
                $name
            );
            $this->assertSame(1, SuperglobalNameJitHelper::isSuperglobalName($name), $name);
        }
        $this->assertSame(0, SuperglobalNameJitHelper::isSuperglobalName('foo'));
        $this->assertSame(0, SuperglobalNameJitHelper::isSuperglobalName('_get'));
        $this->assertSame(0, SuperglobalNameJitHelper::isSuperglobalName('not_super'));
    }

    public function testSpineBundleIncludesSuperglobalNameJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SuperglobalNameJitHelper.php', $spine);
        $this->assertStringContainsString('SuperglobalNameRuntime.php', $spine);
    }
}
