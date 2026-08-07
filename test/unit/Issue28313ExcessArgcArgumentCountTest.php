<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for string/hash helpers (#28313).
 *
 * php-src: ext/standard/string.stub.php, crc32.c, basic_functions.stub.php
 */
final class Issue28313ExcessArgcArgumentCountTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28313.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28313.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('str_rot13_ok:OK:no', $out);
        $this->assertStringContainsString('crc32_ok:OK:2356372769', $out);
        $this->assertStringContainsString(
            'str_shuffle:ArgumentCountError:str_shuffle() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'str_rot13:ArgumentCountError:str_rot13() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'hebrev:ArgumentCountError:hebrev() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'quoted_printable_decode:ArgumentCountError:quoted_printable_decode() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'crc32:ArgumentCountError:crc32() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'md5:ArgumentCountError:md5() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'sha1:ArgumentCountError:sha1() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
        $this->assertStringNotContainsString('seed must be', $out);
    }
}
