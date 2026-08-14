<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * PDO::getAvailableDrivers / pdo_drivers ArgumentCountError (#30994).
 *
 * php-src: ext/pdo/pdo.c
 */
final class Issue30994PdoGetAvailableDriversExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30994_pdo_getavailabledrivers_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30994_pdo_getavailabledrivers_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: PDO::getAvailableDrivers() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: pdo_drivers() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
        $this->assertStringNotContainsString('TypeError', $out);
        $this->assertStringNotContainsString('array (', $out);
    }
}
