<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamSync;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * fsync()/fdatasync() LLVM helpers via libc after stream resolve (#6062, #6813, #9815, #26929).
 *
 * @group aot-lint
 */
final class StreamSyncRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamSyncHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamSync::ensureLinked($ctx);

        foreach ([
            '__compiler_fsync',
            '__compiler_fdatasync',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }

        $this->assertNotNull($ctx->module->getNamedFunction('fsync'), 'libc fsync must be declared');
        $this->assertNotNull($ctx->module->getNamedFunction('fdatasync'), 'libc fdatasync must be declared');
        $this->assertArrayNotHasKey(
            \strtolower('PHPCompiler\\ext\\standard\\StreamSyncJitHelper::syncFileno'),
            $ctx->functions,
            'NestedJIT StreamSyncJitHelper must not be compiled into standalone (#26929)'
        );
    }
}
