<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamRead;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * stream read/seek/lock LLVM helpers via NestedJIT StreamReadJitHelper (#5343, #20982).
 *
 * @group aot-lint
 */
final class StreamReadRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamReadHelpersForUserScriptAot(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            StreamRead::ensureLinked($ctx);

            foreach ([
                '__compiler_flock',
                '__compiler_fpassthru',
                '__compiler_ftruncate',
                '__compiler_ftell',
                '__compiler_fgetc',
                '__compiler_fgets',
                '__compiler_stream_get_line',
                '__compiler_fseek',
                '__compiler_stream_get_contents',
                '__compiler_stream_copy_to_stream',
            ] as $name) {
                $fn = $ctx->lookupFunction($name);
                $this->assertNotNull($fn, $name);
                $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
                $blockName = (string) (iterator_to_array($fn->getBasicBlocks())[0]?->getName() ?? '');
                $this->assertTrue(
                    str_contains($blockName, 'stream_read_') || str_contains($blockName, 'stream_get_line_'),
                    $name.' entry block should be NestedJIT bridge, got: '.$blockName
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
