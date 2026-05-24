<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class TernaryMergeFrameTest extends TestCase
{
    public function testEchoTernaryProducesBranchValue(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile('<?php echo true ? "y" : "n";', 'ternary_true.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame('y', $out);
    }

    public function testEchoTernaryFalseBranch(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile('<?php echo false ? "y" : "n";', 'ternary_false.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame('n', $out);
    }
}
