<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * getservbyname/getservbyport ArgumentCountError wording matches Zend (#30567).
 *
 * php-src: ext/standard/network.c
 */
final class Issue30567GetservExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30567_getserv_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30567_getserv_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'getservbyname_hi:ArgumentCountError:getservbyname() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'getservbyname_lo:ArgumentCountError:getservbyname() expects exactly 2 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'getservbyport_hi:ArgumentCountError:getservbyport() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'getservbyport_lo:ArgumentCountError:getservbyport() expects exactly 2 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok_getservbyname:1', $out);
        $this->assertStringContainsString('ok_getservbyport:1', $out);
        $this->assertStringNotContainsString('requires exactly', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
