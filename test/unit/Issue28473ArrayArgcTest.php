<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess/missing argc → ArgumentCountError for array_filter/reduce/walk (#28473).
 *
 * php-src: ext/standard/array.stub.php
 */
final class Issue28473ArrayArgcTest extends TestCase
{
    public function testVmArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28473.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28473.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertStringContainsString(
            'array_filter/0:ArgumentCountError:array_filter() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'array_filter/4:ArgumentCountError:array_filter() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'array_reduce/0:ArgumentCountError:array_reduce() expects at least 2 arguments, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'array_reduce/1:ArgumentCountError:array_reduce() expects at least 2 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'array_reduce/4:ArgumentCountError:array_reduce() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'array_walk/0:ArgumentCountError:array_walk() expects at least 2 arguments, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'array_walk/1:ArgumentCountError:array_walk() expects at least 2 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'array_walk/4:ArgumentCountError:array_walk() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString('array_filter_ok:1,2,3', $out);
        $this->assertStringContainsString('array_reduce_ok:6', $out);
        $this->assertStringContainsString('array_walk_ok:2,4', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires one to three', $out);
        $this->assertStringNotContainsString('requires two or three', $out);
    }
}
