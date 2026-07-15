<?php
declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #19097 — clone on non-object throws catchable Error (Zend/zend_clones.c). */
final class CloneNullGapTest extends TestCase
{
    public function testCloneNullThrowsCatchableError(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_clone_null.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_clone_null.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Error: __clone method called on non-object\nError: __clone method called on non-object\n",
            $output
        );
    }
}
