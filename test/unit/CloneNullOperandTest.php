<?php
declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #19097 — clone null throws catchable Error at runtime (Zend/zend_clones.c). */
final class CloneNullOperandTest extends TestCase
{
    public function testCloneNullInTryCatch(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_clone_null.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_clone_null.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Error: __clone method called on non-object\n",
            $output
        );
    }
}
