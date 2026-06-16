<?php
declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #9114 — clone inside try must inherit outer locals (Zend/zend_execute.c). */
final class CloneInTryGapTest extends TestCase
{
    public function testCloneInsideTryReturnsClonedObject(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_clone_in_try.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_clone_in_try.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("int(1)\n", $output);
    }
}
