<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitStreamBucketKernel;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6323: stream_bucket_* runtime must LLVM-lower in standalone AOT mode.
 *
 * @group aot-lint
 */
final class StreamBucketStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesBucketHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        JitStreamBucketKernel::ensureStandaloneBodies($ctx);

        foreach ([
            '__compiler_stream_bucket_register',
            '__compiler_stream_bucket_data',
            '__compiler_is_bucket_resource',
            '__compiler_is_brigade_resource',
            '__compiler_stream_brigade_alloc',
            '__compiler_stream_bucket_brigade_push',
            '__compiler_stream_bucket_brigade_pop',
            '__compiler_stream_bucket_object_new',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
