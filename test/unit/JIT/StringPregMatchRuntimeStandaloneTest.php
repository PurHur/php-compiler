<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5289: preg_* LLVM helpers replace lib/AOT/runtime/preg_match.c.
 *
 * @group aot-lint
 */
final class StringPregMatchRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesPregMatchC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/preg_match.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringPregMatchStandaloneLlvm.php');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringPregMatchJit.php');
        $this->assertStringContainsString('__compiler_preg_match', $jit);
        $this->assertStringContainsString('PregMatchRuntime', $jit);
        $this->assertStringNotContainsString('StringPregMatchStandaloneLlvm', $jit);
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('preg_match.c', $linker);
        $bridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringPregMatch.php');
        $this->assertStringContainsString('StringPregMatchJit', $bridge);
        $this->assertStringNotContainsString('preg_match.c', $bridge);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('replaceCallbackArgv', $runtime);
        $this->assertStringNotContainsString('pcre2_match_8', $runtime);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedDefinesPregRuntimeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringPregMatch::ensureLinked($ctx);

        foreach ([
            '__compiler_preg_match',
            '__compiler_preg_match_ex',
            '__compiler_preg_match_all',
            '__compiler_preg_match_all_ex',
            '__compiler_preg_replace',
            '__compiler_preg_replace_callback',
            '__compiler_preg_replace_callback_thin',
            '__compiler_preg_split',
            '__compiler_preg_last_error',
            '__compiler_preg_last_error_msg',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
