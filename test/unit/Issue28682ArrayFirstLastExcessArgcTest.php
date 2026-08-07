<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for array_first/array_last (#28682).
 *
 * php-src: ext/standard/array.c / array.stub.php
 */
final class Issue28682ArrayFirstLastExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
        try {
            $code = file_get_contents(__DIR__.'/../repro/issue_28682.php');
            $this->assertNotFalse($code);
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'issue_28682.php');
            ob_start();
            $rt->run($block);
            $out = ob_get_clean();
            $this->assertStringContainsString('array_first_ok:OK:10', $out);
            $this->assertStringContainsString('array_last_ok:OK:20', $out);
            $this->assertStringContainsString(
                'array_first_excess:ArgumentCountError:array_first() expects exactly 1 argument, 2 given',
                $out
            );
            $this->assertStringContainsString(
                'array_first_zero:ArgumentCountError:array_first() expects exactly 1 argument, 0 given',
                $out
            );
            $this->assertStringContainsString(
                'array_last_excess:ArgumentCountError:array_last() expects exactly 1 argument, 2 given',
                $out
            );
            $this->assertStringContainsString(
                'array_last_zero:ArgumentCountError:array_last() expects exactly 1 argument, 0 given',
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
