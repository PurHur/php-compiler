<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ClassConstFetch as call arg after sibling binary-op must not steal plus result (#26990).
 */
final class ClassConstFetchArgAfterBinopSiblingTest extends TestCase
{
    public function testMethodAndFunctionCallArgsMatchZendAfterJumpIf(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/classconstfetch_arg_after_binop_26990.php');
        self::assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'classconstfetch_arg_after_binop_26990.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("10-21-30\n10-21-30\n", $out);
    }

    public function testIssueInlineMethodCallShape(): void
    {
        $code = <<<'PHP'
<?php
class Box {
    public const X = 10;
    public const Y = 20;
    public const Z = 30;
    public function get($n) { return $n; }
}
if (!class_exists('Box')) { echo "no\n"; exit; }
$b = new Box;
echo $b->get(Box::X), '-', $b->get(Box::Y)+1, '-', $b->get(Box::Z), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'ccf_arg_issue.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("10-21-30\n", $out);
    }
}
