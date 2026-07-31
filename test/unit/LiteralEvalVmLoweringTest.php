<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Block;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PHPCompiler\Block::containsNonLiteralEvalOpcodes
 * @covers \PHPCompiler\Block::literalEvalSourceNeedsVm
 * @covers issue #25535
 */
final class LiteralEvalVmLoweringTest extends TestCase
{
    public function testExpressionOnlyLiteralEvalStaysOnMcjit(): void
    {
        self::assertFalse(Block::literalEvalSourceNeedsVm('echo "hi";'));
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            "<?php\neval('echo \"hi\";');\n",
            'literal_eval_expr.php'
        );
        self::assertFalse(Block::requiresVmLowering($block));
    }

    public function testClassLiteralEvalRequiresVmLowering(): void
    {
        self::assertTrue(
            Block::literalEvalSourceNeedsVm('class T { final public int $x = 1; }')
        );
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            "<?php\neval('class T { final public int \$x = 1; }');\necho \"parsed_ok\\n\";\n",
            'literal_eval_final_plain.php'
        );
        self::assertTrue(Block::requiresVmLowering($block));
    }

    /**
     * @covers issue #26169 — decl literal eval must be probed before AOT emitFalse
     */
    public function testDeclLiteralEvalIsDetectedForAotProbe(): void
    {
        self::assertTrue(Block::literalEvalSourceNeedsVm('class T { final public int $x = 1; }'));
        self::assertTrue(Block::literalEvalSourceNeedsVm('function f() {}'));
        self::assertFalse(Block::literalEvalSourceNeedsVm('$x = 1;'));
    }

    public function testNonLiteralEvalStillRequiresVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            "<?php\n\$s = 'echo 1;';\neval(\$s);\n",
            'dyn_eval.php'
        );
        self::assertTrue(Block::requiresVmLowering($block));
    }
}
