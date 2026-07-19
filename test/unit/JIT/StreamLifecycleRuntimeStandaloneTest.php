<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamLifecycle;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * is_resource/fclose/feof/fflush LLVM helpers via NestedJIT StreamLifecycleJitHelper (#5343, #20966).
 *
 * @group aot-lint
 */
final class StreamLifecycleRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamLifecycleHelpersForUserScriptAot(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            StreamLifecycle::ensureLinked($ctx);

            foreach ([
                '__compiler_is_resource',
                '__compiler_fclose',
                '__compiler_feof',
                '__compiler_fflush',
                '__compiler_pclose',
            ] as $name) {
                $fn = $ctx->lookupFunction($name);
                $this->assertNotNull($fn, $name);
                $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
                $this->assertStringContainsString(
                    'stream_lifecycle_',
                    (string) (iterator_to_array($fn->getBasicBlocks())[0]?->getName() ?? '')
                );
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
}
