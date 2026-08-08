<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess/missing argc → ArgumentCountError for math/CSPRNG/password builtins (#28476).
 *
 * php-src: ext/standard/math.stub.php, random.stub.php, password.c
 */
final class Issue28476MathRandomArgcTest extends TestCase
{
    public function testVmArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28476.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28476.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertStringContainsString(
            'ceil/0:ArgumentCountError:ceil() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'ceil/2:ArgumentCountError:ceil() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'floor/0:ArgumentCountError:floor() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'bindec/0:ArgumentCountError:bindec() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'hexdec/2:ArgumentCountError:hexdec() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'random_bytes/0:ArgumentCountError:random_bytes() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'random_int/0:ArgumentCountError:random_int() expects exactly 2 arguments, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'random_int/3:ArgumentCountError:random_int() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'password_verify/0:ArgumentCountError:password_verify() expects exactly 2 arguments, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'password_verify/3:ArgumentCountError:password_verify() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString('ceil_ok:2', $out);
        $this->assertStringContainsString('floor_ok:1', $out);
        $this->assertStringContainsString('bindec_ok:10', $out);
        $this->assertStringContainsString('hexdec_ok:255', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly', $out);
    }
}
