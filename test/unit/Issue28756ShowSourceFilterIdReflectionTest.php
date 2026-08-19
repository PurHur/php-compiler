<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Reflection metadata for show_source()/filter_id() matches php-src stubs (#28756).
 */
final class Issue28756ShowSourceFilterIdReflectionTest extends TestCase
{
    public function testVmReflectionSignaturesMatchZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28756_show_source_filter_id_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28756_show_source_filter_id_reflection.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();

        $this->assertSame(
            "show_source.p0=string\n"
            ."show_source.p1=bool\n"
            ."show_source.ret=string|bool\n"
            ."filter_id.p0=string\n"
            ."filter_id.ret=int|false\n"
            ."filter_id.unknown=false\n",
            $out
        );
    }
}
