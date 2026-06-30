<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\EncapsedCoalesceDesugar;
use PHPUnit\Framework\TestCase;

final class EncapsedCoalesceDesugarTest extends TestCase
{
    public function testPureCurlyCoalesce(): void
    {
        $src = '<?php echo "{$a[\'missing\'] ?? \'nil\'}";';
        $out = EncapsedCoalesceDesugar::desugar($src);
        $this->assertStringContainsString("(\$a['missing'] ?? 'nil')", $out);
        $this->assertStringNotContainsString('{$', $out);
    }

    public function testMixedLiteralAndCoalesce(): void
    {
        $src = '<?php echo "hello {$x ?? \'y\'} world";';
        $out = EncapsedCoalesceDesugar::desugar($src);
        $this->assertStringContainsString("'hello '", $out);
        $this->assertStringContainsString("(\$x ?? 'y')", $out);
        $this->assertStringContainsString("' world'", $out);
        $this->assertStringContainsString(' . ', $out);
    }

    public function testNoOpWithoutCoalesce(): void
    {
        $code = '<?php echo "{$a->p}";';
        $this->assertSame($code, EncapsedCoalesceDesugar::desugar($code));
    }

    public function testNoOpSingleQuoted(): void
    {
        $code = "<?php echo 'no ?? here';";
        $this->assertSame($code, EncapsedCoalesceDesugar::desugar($code));
    }
}
