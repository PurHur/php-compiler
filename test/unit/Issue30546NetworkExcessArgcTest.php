<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Network/DNS builtins ArgumentCountError wording matches Zend (#30546).
 *
 * php-src: ext/standard/basic_functions.c / network.c / dns.c
 */
final class Issue30546NetworkExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30546_network_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30546_network_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'inet_pton_hi:ArgumentCountError:inet_pton() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'inet_pton_lo:ArgumentCountError:inet_pton() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'inet_ntop_hi:ArgumentCountError:inet_ntop() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ip2long_hi:ArgumentCountError:ip2long() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'getprotobyname_hi:ArgumentCountError:getprotobyname() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'getprotobynumber_hi:ArgumentCountError:getprotobynumber() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'gethostbyaddr_hi:ArgumentCountError:gethostbyaddr() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'checkdnsrr_hi:ArgumentCountError:checkdnsrr() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'checkdnsrr_lo:ArgumentCountError:checkdnsrr() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString('ok_inet:1', $out);
        $this->assertStringContainsString('ok_ip2long:1', $out);
        $this->assertStringContainsString('ok_proto_call:1', $out);
        $this->assertStringNotContainsString('requires exactly', $out);
        $this->assertStringNotContainsString('requires one or two', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
