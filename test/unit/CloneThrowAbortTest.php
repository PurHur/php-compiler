<?php
declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Issue #12068 — clone when __clone() throws must not complete assignment. */
final class CloneThrowAbortTest extends TestCase
{
    public function testCloneThrowInTryLeavesOkFalse(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_clone_throw_abort.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_clone_throw_abort.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('false', $output);
    }
}
