<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * readfile() optional args + excess argc (#30582).
 *
 * php-src: ext/standard/file.c / basic_functions.stub.php
 */
final class Issue30582ReadfileOptionalArgsTest extends TestCase
{
    public function testVmOptionalArgsAndExcessArgc(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_readfile_optional_args_30582.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_readfile_optional_args_30582.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString("ok2 n=12 body=hello-30582\n", $out);
        $this->assertStringContainsString("ok3 n=12 body=hello-30582\n", $out);
        $this->assertStringContainsString('readfile() expects at most 3 arguments, 4 given', $out);
        $this->assertStringContainsString(
            'readfile(): Argument #3 ($context) must be of type resource or null, int given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('compiler build', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
    }
}
