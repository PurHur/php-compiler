<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** preg AOT defer gate uses CFG literal calls, not source regex (#16075). */
final class PregPrelinkDeferRuntimeTest extends TestCase
{
    public function testContainsPregPrelinkBuiltinCallsIgnoresStringLiterals(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $literal = $runtime->parseAndCompile(
            '<?php $x = "preg_match"; echo "ok";',
            'literal.php'
        );
        $this->assertNotNull($literal);
        $this->assertFalse(Block::containsPregPrelinkBuiltinCalls($literal));

        $call = $runtime->parseAndCompile(
            '<?php preg_match("/x/", "x"); echo "ok";',
            'call.php'
        );
        $this->assertNotNull($call);
        $this->assertTrue(Block::containsPregPrelinkBuiltinCalls($call));
    }

    public function testRuntimeStandaloneUsesBlockGateNotSourceRegex(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Runtime.php');
        $this->assertStringContainsString('Block::containsPregPrelinkBuiltinCalls($block)', $source);
        $this->assertStringNotContainsString(
            "preg_match('/\\bpreg_(?:match(?:_all)?|replace",
            $source
        );
        $this->assertStringContainsString(
            'PregMatchUserScriptLlvm stubs',
            $source
        );
        $this->assertStringNotContainsString('$this->jitContext = null;', $source);
    }
}
