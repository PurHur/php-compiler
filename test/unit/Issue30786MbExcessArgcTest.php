<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mbstring excess argc → Zend at-most ArgumentCountError (#30786).
 *
 * php-src: ext/mbstring/mbstring.c
 */
final class Issue30786MbExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsAtMostArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30786_mb_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30786_mb_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "mb_str_split:ArgumentCountError:mb_str_split() expects at most 3 arguments, 4 given\n"
            ."mb_convert_case:ArgumentCountError:mb_convert_case() expects at most 3 arguments, 4 given\n"
            ."mb_scrub:ArgumentCountError:mb_scrub() expects at most 2 arguments, 3 given\n"
            ."mb_substr_count:ArgumentCountError:mb_substr_count() expects at most 3 arguments, 4 given\n"
            ."mb_str_split_lo:ArgumentCountError:mb_str_split() expects at least 1 argument, 0 given\n"
            ."ok_split:a,b\n"
            ."ok_case:A\n"
            ."ok_count:2\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
        $this->assertStringNotContainsString('ACCEPTED', $out);
    }
}
