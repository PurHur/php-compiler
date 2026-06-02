<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class TernaryReturnMergeSlotTest extends TestCase
{
    public function testReturnTernaryTrueBranchValue(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php declare(strict_types=1); function f(bool $flag): int|true { return $flag ? 1 : true; } echo f(true), "\\n", f(false) === true ? "ok" : "no", "\\n";',
            'ternary_return.php'
        );
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame("1\nok\n", $out);
    }
}
