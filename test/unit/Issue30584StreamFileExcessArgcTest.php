<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for stream/file builtins (#30584).
 *
 * php-src: ext/standard/file.c / streamsfuncs.c
 */
final class Issue30584StreamFileExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_stream_file_excess_argc_30584.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_stream_file_excess_argc_30584.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "fpassthru() expects exactly 1 argument, 2 given\n"
            ."fflush() expects exactly 1 argument, 2 given\n"
            ."fseek() expects at most 3 arguments, 4 given\n"
            ."ftell() expects exactly 1 argument, 2 given\n"
            ."feof() expects exactly 1 argument, 2 given\n"
            ."fgetc() expects exactly 1 argument, 2 given\n"
            ."rewind() expects exactly 1 argument, 2 given\n"
            ."stream_get_meta_data() expects exactly 1 argument, 2 given\n"
            ."stream_context_create() expects at most 2 arguments, 3 given\n"
            ."stream_context_set_option() expects at most 4 arguments, 5 given\n"
            ."stream_context_get_params() expects exactly 1 argument, 2 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('compiler build', $out);
    }
}
