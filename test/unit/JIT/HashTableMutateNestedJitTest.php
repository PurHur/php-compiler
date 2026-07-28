<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * NestedJIT must lower HashTable::{replacePackedValues,assignPackedList,reorderKeyedPairs} (#24157).
 *
 * @group aot-lint
 */
final class HashTableMutateNestedJitTest extends TestCase
{
    public function testMutateNestedProbeNestedCompile(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        foreach ([
            'add', 'addindex', 'updateindex', 'append',
            'replacepackedvalues', 'assignpackedlist', 'reorderkeyedpairs',
            'iterate', 'exportkeyvaluepairs', 'getnumelements', 'find', 'findindex',
        ] as $htMethod) {
            NestedVmHashTableMethodLlvm::ensureMethod($ctx, $htMethod);
        }
        foreach (['null', 'int', 'string', 'array', 'copyfrom', 'duplicatefrom', 'resolveindirect', 'toint', 'tostring'] as $method) {
            NestedVmVariableMethodLlvm::ensureMethod($ctx, $method);
        }

        NestedJitCompileScope::run($ctx, static function () use ($ctx, $runtime): void {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
            $jit = new \PHPCompiler\JIT($ctx);
            $path = __DIR__.'/Fixtures/HashTableMutateNestedProbe.php';
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'HashTableMutateNestedProbe.php');
            self::assertNotNull($block);
            $jit->compile($block);
        });
    }
}
