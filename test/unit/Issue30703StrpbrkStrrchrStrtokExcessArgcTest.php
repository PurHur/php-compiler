<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * strpbrk/strrchr/strtok excess argc → Zend ArgumentCountError (#30703).
 *
 * php-src: ext/standard/string.c
 */
final class Issue30703StrpbrkStrrchrStrtokExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30703_strpbrk_strrchr_strtok_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30703_strpbrk_strrchr_strtok_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "strpbrk:ArgumentCountError:strpbrk() expects exactly 2 arguments, 3 given\n"
            ."strrchr:ArgumentCountError:strrchr() expects exactly 2 arguments, 3 given\n"
            ."strtok:ArgumentCountError:strtok() expects at most 2 arguments, 3 given\n"
            ."ok:'bc'\n"
            ."ok:'bc'\n"
            ."ok:'a'\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('this compiler build', $out);
    }
}
