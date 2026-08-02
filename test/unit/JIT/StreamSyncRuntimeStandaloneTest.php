<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamSync;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * fsync()/fdatasync() LLVM helpers via libc (#6062, #6813, #9815, #26929).
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

        foreach (['fsync', 'fdatasync', 'fflush', 'fileno'] as $name) {
            $this->assertNotNull(
                $ctx->functions[\strtolower($name)] ?? $ctx->module->getNamedFunction($name),
                $name.' must be declared for StreamSync libc emit'
            );
        }
    }
}
