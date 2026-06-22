<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ValueEchoRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9225: AOT standalone echo type bridges must not nested-compile ValueEchoJitHelper LLVM.
 *
 * @group aot-lint
 */
final class ValueEchoRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesTypeBridgesForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ValueEchoRuntime::implement($ctx);

        foreach ([
            '__value_echo__typeIsNull',
            '__value_echo__typeIsNativeLong',
            '__value_echo__typeIsNativeBool',
            '__value_echo__typeIsNativeDouble',
            '__value_echo__typeIsString',
            '__value_echo__typeIsHashtable',
            '__value_echo__typeIsObject',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
