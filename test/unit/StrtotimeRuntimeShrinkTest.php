<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrtotimeJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * strtotime() NestedJIT via JitVmHelperLink::ensureCompiled (#23832 / peer #23787).
 */
final class StrtotimeRuntimeShrinkTest extends TestCase
{
    public function testStringStrtotimeUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrtotime.php');
        $this->assertStringContainsString('StrtotimeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testStrtotimeJitHelperParsesDatetime(): void
    {
        $tag = StrtotimeJitHelper::strtotimeArgv('2020-06-21', 0, 0);
        $this->assertSame(StrtotimeJitHelper::TAG_INT, $tag);
        $this->assertSame(strtotime('2020-06-21'), StrtotimeJitHelper::lastTimestamp());

        $falseTag = StrtotimeJitHelper::strtotimeArgv('not-a-date-xxx', 0, 0);
        $this->assertSame(StrtotimeJitHelper::TAG_FALSE, $falseTag);
    }
}
