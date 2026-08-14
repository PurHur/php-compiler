<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * sizeof() excess argc → ArgumentCountError cites sizeof(), not count() (#30686).
 *
 * php-src: ext/standard/array.c
 */
final class Issue30686SizeofExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30686_sizeof_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30686_sizeof_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "sz_hi:ArgumentCountError:sizeof() expects at most 2 arguments, 3 given\n"
            ."sz_lo:ArgumentCountError:sizeof() expects at least 1 argument, 0 given\n"
            ."sz_ok:2\n"
            ."ct_hi:ArgumentCountError:count() expects at most 2 arguments, 3 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString(
            'sz_hi:ArgumentCountError:count() expects at most 2 arguments, 3 given',
            $out
        );
    }
}
