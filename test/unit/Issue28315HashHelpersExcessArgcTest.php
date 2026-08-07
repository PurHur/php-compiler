<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError / TypeError for hash helpers (#28315).
 *
 * php-src: ext/hash/hash.stub.php / hash.c
 */
final class Issue28315HashHelpersExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28315.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28315.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('hash_file_ok:OK:', $out);
        $this->assertStringContainsString('hash_file_options_ok:OK:', $out);
        $this->assertStringContainsString(
            'hash_file_options:TypeError:hash_file(): Argument #4 ($options) must be of type array, string given',
            $out
        );
        $this->assertStringContainsString(
            'hash_hmac_file:ArgumentCountError:hash_hmac_file() expects at most 4 arguments, 5 given',
            $out
        );
        $this->assertStringContainsString(
            'hash_update:ArgumentCountError:hash_update() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'hash_final:ArgumentCountError:hash_final() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'hash_copy:ArgumentCountError:hash_copy() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'hash_equals:ArgumentCountError:hash_equals() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'hash_hkdf:ArgumentCountError:hash_hkdf() expects at most 5 arguments, 6 given',
            $out
        );
        $this->assertStringContainsString(
            'hash_hmac_ok:ArgumentCountError:hash_hmac() expects at most 4 arguments, 5 given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
