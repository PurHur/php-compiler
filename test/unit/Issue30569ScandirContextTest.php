<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * scandir() optional context + excess argc (#30569).
 *
 * php-src: ext/standard/dir.c / basic_functions.stub.php
 */
final class Issue30569ScandirContextTest extends TestCase
{
    public function testVmOptionalNullContextAndExcessArgc(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_scandir_context_30569.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_scandir_context_30569.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertMatchesRegularExpression('/ok count=\d+/', $out);
        $this->assertStringContainsString(
            'scandir(): Argument #3 ($context) must be of type resource or null, int given',
            $out
        );
        $this->assertStringContainsString('scandir() expects at most 3 arguments, 4 given', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('compiler build', $out);
    }
}
