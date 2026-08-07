<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for time/URL/array builtins (#28691).
 *
 * php-src: ext/standard/basic_functions.stub.php / array.stub.php
 */
final class Issue28691TimeUrlArrayExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28691.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28691.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('microtime_ok:OK:float', $out);
        $this->assertStringContainsString('parse_url_ok:OK:a', $out);
        $this->assertStringContainsString('array_combine_ok:OK:v', $out);
        $this->assertStringContainsString(
            'microtime:ArgumentCountError:microtime() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'hrtime:ArgumentCountError:hrtime() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'sleep:ArgumentCountError:sleep() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'usleep:ArgumentCountError:usleep() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'parse_url:ArgumentCountError:parse_url() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'http_build_query:ArgumentCountError:http_build_query() expects at most 4 arguments, 5 given',
            $out
        );
        $this->assertStringContainsString(
            'array_column:ArgumentCountError:array_column() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'array_combine:ArgumentCountError:array_combine() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
