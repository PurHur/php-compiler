<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ini_parse_quantity leading-zero octal matches Zend (#28763).
 *
 * php-src: Zend/zend_ini.c — zend_ini_parse_quantity
 */
final class Issue28763IniParseQuantityOctalTest extends TestCase
{
    public function testVmLeadingZeroOctalMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28763.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28763.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();

        $this->assertSame(
            "010=8\n0010=8\n+010=8\n-010=-8\n"
            ."08=0 WARN:unknown_multiplier\n09=0 WARN:unknown_multiplier\n"
            ."0o10=8\n0x10=16\n0b10=2\n077=63\n078=7 WARN:unknown_multiplier\n",
            $out
        );
    }
}
