<?php

declare(strict_types=1);

namespace Test\Unit;

use PHPCompiler\JIT\Builtin\CallArgv;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @group llvm
 */
final class CallArgvContextModuleTest extends TestCase
{
    public function testCallArgvGlobalIsPerModuleNotProcessWide(): void
    {
        if (!\is_readable(\getenv('PHP_COMPILER_LLVM_PATH') ?: '')) {
            $llvm = \dirname(__DIR__, 2).'/.llvm';
            if (\is_readable($llvm.'/libLLVM-9.so.1')) {
                \putenv('PHP_COMPILER_LLVM_PATH='.$llvm);
            }
        }
        \putenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK=1');

        $runtime = new Runtime(Runtime::MODE_AOT);
        $first = $runtime->loadJitContext();
        $firstGlobal = CallArgv::ensureGlobal($first);

        $runtime2 = new Runtime(Runtime::MODE_AOT);
        $second = $runtime2->loadJitContext();
        $secondGlobal = CallArgv::ensureGlobal($second);

        self::assertNotSame(
            $first->module,
            $second->module,
            'sanity: nested test uses distinct LLVM modules'
        );
        self::assertNotSame(
            $firstGlobal,
            $secondGlobal,
            'CallArgv LLVM global must not leak across JIT Context modules (#14459)'
        );
    }
}
