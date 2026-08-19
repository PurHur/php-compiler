<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Packed local arrays used only by isset()/print must JIT-compile (#32556 leftover of #32475).
 *
 * Analyzer::canEscape previously threw Not implemented escape operand on
 * PHPCfg\Op\Expr\Isset_ / Print_ (Echo_ / Empty_ were already listed).
 */
final class IssetPrintPackedArrayAnalyzerTest extends TestCase
{
    public function testIssetOnPackedArrayDoesNotTripEscapeAnalyzer(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = [1];
var_dump(isset($a));
PHP;
        $block = $runtime->parseAndCompile($code, 'isset_packed_array.php');
        self::assertNotNull($block);
        $jit = new JIT($runtime->loadJitContext());
        $jit->compile($block);
        self::assertTrue(true);
    }

    public function testPrintArrayLiteralDoesNotTripEscapeAnalyzer(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
print [1];
PHP;
        $block = $runtime->parseAndCompile($code, 'print_array_literal.php');
        self::assertNotNull($block);
        $jit = new JIT($runtime->loadJitContext());
        $jit->compile($block);
        self::assertTrue(true);
    }
}
