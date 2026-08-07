<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for str_increment/str_decrement (#28679).
 *
 * php-src: ext/standard/string.c / basic_functions.stub.php
 */
final class Issue28679StrIncdecExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $code = file_get_contents(__DIR__.'/../repro/issue_28679.php');
            $this->assertNotFalse($code);
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'issue_28679.php');
            ob_start();
            $rt->run($block);
            $out = ob_get_clean();
            $this->assertStringContainsString('str_increment_ok:OK:b', $out);
            $this->assertStringContainsString('str_decrement_ok:OK:a', $out);
            $this->assertStringContainsString(
                'str_increment_excess:ArgumentCountError:str_increment() expects exactly 1 argument, 2 given',
                $out
            );
            $this->assertStringContainsString(
                'str_increment_zero:ArgumentCountError:str_increment() expects exactly 1 argument, 0 given',
                $out
            );
            $this->assertStringContainsString(
                'str_decrement_excess:ArgumentCountError:str_decrement() expects exactly 1 argument, 2 given',
                $out
            );
            $this->assertStringContainsString(
                'str_decrement_zero:ArgumentCountError:str_decrement() expects exactly 1 argument, 0 given',
                $out
            );
            $this->assertStringNotContainsString('LogicException', $out);
            $this->assertStringNotContainsString('in this compiler build', $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
