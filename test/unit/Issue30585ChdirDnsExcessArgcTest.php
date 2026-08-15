<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * chdir/gethostbyname/gethostbynamel ArgumentCountError wording matches Zend (#30585).
 *
 * php-src: ext/standard/dir.c / dns.c
 */
final class Issue30585ChdirDnsExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30585_chdir_dns_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30585_chdir_dns_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'chdir_hi:ArgumentCountError:chdir() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'chdir_lo:ArgumentCountError:chdir() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'gethostbyname_hi:ArgumentCountError:gethostbyname() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'gethostbyname_lo:ArgumentCountError:gethostbyname() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'gethostbynamel_hi:ArgumentCountError:gethostbynamel() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'gethostbynamel_lo:ArgumentCountError:gethostbynamel() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString('ok_chdir:1', $out);
        $this->assertStringContainsString('ok_gethostbyname:1', $out);
        $this->assertStringContainsString('ok_gethostbynamel:1', $out);
        $this->assertStringNotContainsString('requires exactly', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
