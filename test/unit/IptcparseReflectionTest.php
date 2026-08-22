<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** iptcparse Reflection array|false + $iptc_block (#27782, basic_functions.stub.php). */
final class IptcparseReflectionTest extends TestCase
{
    public function testReflectionReturnAndNamedParam(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_27782_iptcparse_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_27782.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "return=array|false\n"
            ."param=iptc_block\n"
            ."false\n"
            ."false\n"
            ."Error: Unknown named parameter \$iptcdata\n",
            ob_get_clean()
        );
    }
}
