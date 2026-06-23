<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SuperglobalNameJitHelper;
use PHPCompiler\ext\standard\SuperglobalNames;
use PHPUnit\Framework\TestCase;

/** SuperglobalNameRuntime routes through SuperglobalNameJitHelper PHP not memcmp LLVM (#9271). */
final class SuperglobalNameRuntimeShrinkTest extends TestCase
{
    public function testSuperglobalNameRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalNameRuntime.php');
        $this->assertStringContainsString('SuperglobalNameJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('memcmp')", $source);
        $this->assertStringNotContainsString('identicalToAsciiLiteral', $source);
        $this->assertLessThan(140, \substr_count($source, "\n") + 1);
    }

    public function testSuperglobalNameJitHelperDelegatesToSuperglobalNames(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/SuperglobalNameJitHelper.php');
        $this->assertStringContainsString('SuperglobalNames::isSuperglobalName', $source);
    }

    public function testSuperglobalNameJitHelperSemanticsMatchTable(): void
    {
        $this->assertSame(1, SuperglobalNameJitHelper::isSuperglobalName('_GET'));
        $this->assertSame(1, SuperglobalNameJitHelper::isSuperglobalName('GLOBALS'));
        $this->assertSame(0, SuperglobalNameJitHelper::isSuperglobalName('not_super'));
        $this->assertSame(
            SuperglobalNames::isSuperglobalName('_SESSION') ? 1 : 0,
            SuperglobalNameJitHelper::isSuperglobalName('_SESSION')
        );
    }
}
