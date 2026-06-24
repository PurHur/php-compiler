<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringStrtr;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9392: AOT standalone strtr via StrtrTwoStringJitHelper PHP + array LLVM quarantine.
 *
 * @group aot-lint
 */
final class StrtrRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesStrtrRuntimeHelpers(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringStrtr::ensureStandaloneBodies($ctx);

        $twoString = $ctx->lookupFunction('__compiler_strtr');
        $this->assertNotNull($twoString, '__compiler_strtr must be linked for standalone AOT');
        $this->assertGreaterThan(0, $twoString->countBasicBlocks(), '__compiler_strtr must have LLVM bridge body');

        $array = $ctx->lookupFunction('__compiler_strtr_array');
        $this->assertNotNull($array, '__compiler_strtr_array must be linked for standalone AOT');
        $this->assertGreaterThan(0, $array->countBasicBlocks(), '__compiler_strtr_array must have LLVM body');

        $this->assertNotNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\StrtrTwoStringJitHelper::strtrTwoString')] ?? null,
            'StrtrTwoStringJitHelper must be compiled into standalone module'
        );
        $this->assertNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\StrtrArrayJitHelper::strtrArray')] ?? null,
            'StrtrArrayJitHelper must not compile in standalone (LLVM quarantine)'
        );
    }
}
