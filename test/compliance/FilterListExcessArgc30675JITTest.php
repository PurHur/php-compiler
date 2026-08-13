<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: filter_list() excess argc → ArgumentCountError (#30675).
 *
 * @group llvm
 */
final class FilterListExcessArgc30675JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_filter_list_30675.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_filter_list_30675.phpt',
            'excess_argc_filter_list_30675.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }
}
