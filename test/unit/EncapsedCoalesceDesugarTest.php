<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\EncapsedCoalesceDesugar;
use PHPUnit\Framework\TestCase;

final class EncapsedCoalesceDesugarTest extends TestCase
{
    public function testDesugarsArrayDimCoalesce(): void
    {
        $code = '<?php echo "{$a[\'b\'] ?? 0}";';
        $out = EncapsedCoalesceDesugar::desugar($code);
        $this->assertStringNotContainsString('"{$a', $out);
        $this->assertStringContainsString("(\$a['b'] ?? 0)", $out);
    }

    public function testDesugarsSuperglobalCoalesce(): void
    {
        $code = '<?php echo "{$_SERVER[\'PHP_SELF\'] ?? \'fallback\'}";';
        $out = EncapsedCoalesceDesugar::desugar($code);
        $this->assertStringContainsString("(\$_SERVER['PHP_SELF'] ?? 'fallback')", $out);
    }

    public function testNoOpWithoutCoalesce(): void
    {
        $code = '<?php echo "{$a->p}";';
        $this->assertSame($code, EncapsedCoalesceDesugar::desugar($code));
    }

    public function testNoOpCoalesceOutsideEncapsed(): void
    {
        $code = '<?php echo $a ?? 0;';
        $this->assertSame($code, EncapsedCoalesceDesugar::desugar($code));
    }
}
