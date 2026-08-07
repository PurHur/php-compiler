<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * stream_context_set_options wrong argc → ArgumentCountError (#28680).
 *
 * php-src: ext/standard/streamsfuncs.c / basic_functions.stub.php
 */
final class Issue28680StreamContextSetOptionsExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $code = file_get_contents(__DIR__.'/../repro/issue_28680.php');
            $this->assertNotFalse($code);
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'issue_28680.php');
            ob_start();
            $rt->run($block);
            $out = ob_get_clean();
            $this->assertStringContainsString(
                '0:ArgumentCountError:stream_context_set_options() expects exactly 2 arguments, 0 given',
                $out
            );
            $this->assertStringContainsString(
                '1:ArgumentCountError:stream_context_set_options() expects exactly 2 arguments, 1 given',
                $out
            );
            $this->assertStringContainsString(
                '3:ArgumentCountError:stream_context_set_options() expects exactly 2 arguments, 3 given',
                $out
            );
            $this->assertStringContainsString('ok:true', $out);
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
