<?php

declare(strict_types=1);

namespace PHPCompiler;

/** Guard 14-element deferred inline array literals (#14134, Zend/zend_compile.c). */
final class ArrayLiteral14DeferredTempsTest extends \PHPUnit\Framework\TestCase
{
    public function testMaintainerGapArrayLiteral14NullRepro(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile(
            __DIR__ . '/../repro/maintainer_gap_array_literal_14_null.php'
        ));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testMaintainerGapMbEncodingMetadataRepro(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile(
            __DIR__ . '/../repro/maintainer_gap_mb_encoding_metadata.php'
        ));
        $this->assertSame("ok\n", ob_get_clean());
    }
}
