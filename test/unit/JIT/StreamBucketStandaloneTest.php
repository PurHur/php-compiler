<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitStreamBucketKernel;
use PHPCompiler\JIT\Builtin\StreamBucket;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6323 / #20998: stream_bucket_* runtime NestedJIT via StreamBucketJitHelper.
 *
 * @group aot-lint
 */
final class StreamBucketStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesBucketHelpersForUserScriptAot(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            StreamBucket::ensureLinked($ctx);

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
        } finally {
            if (false === $prev || '' === (string) $prev) {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
            } else {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prev);
                $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = $prev;
                $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = $prev;
            }
        }
    }

    public function testKernelEnsureLinkedAliasesStandaloneBodies(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3).'/ext/standard/JitStreamBucketKernel.php');
        $this->assertStringContainsString('public static function ensureLinked', $source);
        $this->assertStringContainsString('public static function ensureStandaloneBodies', $source);
        $this->assertStringContainsString('self::implement($context)', $source);
        $this->assertStringNotContainsString('ensureDeferredStubsForInventoryEmit', $source);
        // Silence unused import when PHPUnit filters this method alone.
        $this->assertTrue(\class_exists(JitStreamBucketKernel::class));
    }
}
