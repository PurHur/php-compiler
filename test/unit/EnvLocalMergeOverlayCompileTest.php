<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * #12810 regression: EnvLocalJitHelper mergeOverlay must not nest-compile Variable::string().
 *
 * @group llvm
 */
final class EnvLocalMergeOverlayCompileTest extends TestCase
{
    public function testBinCompilePhpParseAndCompileDoesNotFailOnEnvLocalMergeOverlay(): void
    {
        if (!\class_exists(\PHPLLVM\Context::class)) {
            self::markTestSkipped('LLVM not available');
        }

        $source = (string) file_get_contents(__DIR__.'/../../bin/compile.php');
        $runtime = new Runtime(Runtime::MODE_AOT);
        if (\function_exists('putenv')) {
            putenv('PHP_COMPILER_SELFHOST_AOT=1');
        }

        $block = $runtime->parseAndCompile($source, 'bin/compile.php');

        self::assertNotNull($block, 'bin/compile.php parseAndCompile should not fail on EnvLocal merge overlay (#12810)');
    }
}
