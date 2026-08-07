<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * http_* / get_*_handler excess argc → ArgumentCountError (#28683).
 *
 * php-src: ext/standard/http.c / basic_functions.c
 */
final class Issue28683HttpHandlerExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
        try {
            $code = file_get_contents(__DIR__.'/../repro/issue_28683.php');
            $this->assertNotFalse($code);
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'issue_28683.php');
            ob_start();
            $rt->run($block);
            $out = ob_get_clean();
            $this->assertStringContainsString(
                'http_get:ArgumentCountError:http_get_last_response_headers() expects exactly 0 arguments, 1 given',
                $out
            );
            $this->assertStringContainsString(
                'http_clear:ArgumentCountError:http_clear_last_response_headers() expects exactly 0 arguments, 1 given',
                $out
            );
            $this->assertStringContainsString(
                'get_error:ArgumentCountError:get_error_handler() expects exactly 0 arguments, 1 given',
                $out
            );
            $this->assertStringContainsString(
                'get_exception:ArgumentCountError:get_exception_handler() expects exactly 0 arguments, 1 given',
                $out
            );
            $this->assertStringContainsString('http_get_ok:OK:NULL', $out);
            $this->assertStringContainsString('http_clear_ok:OK:NULL', $out);
            $this->assertStringNotContainsString('LogicException', $out);
            $this->assertStringNotContainsString('takes no arguments', $out);
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
