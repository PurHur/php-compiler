<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for stream/file builtins (#30721).
 *
 * php-src: ext/standard/file.c
 */
final class Issue30721StreamFileExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30721_stream_file_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30721_stream_file_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "fgets:ArgumentCountError:fgets() expects at most 2 arguments, 3 given\n"
            ."fclose:ArgumentCountError:fclose() expects exactly 1 argument, 2 given\n"
            ."fwrite:ArgumentCountError:fwrite() expects at most 3 arguments, 4 given\n"
            ."fputs:ArgumentCountError:fputs() expects at most 3 arguments, 4 given\n"
            ."stream_get_contents:ArgumentCountError:stream_get_contents() expects at most 3 arguments, 4 given\n"
            ."ok_fgets:ok\n"
            ."ok_fclose:1\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
