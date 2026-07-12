<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\EncapsedCoalesceDesugar;
use PHPUnit\Framework\TestCase;

final class EncapsedCoalesceDesugarTest extends TestCase
{
    /** @var string|false */
    private $prevProfile;

    protected function setUp(): void
    {
        $this->prevProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
    }

    protected function tearDown(): void
    {
        if (false === $this->prevProfile || '' === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
        }
    }

    public function testDesugarsArrayDimCoalesce(): void
    {
        $code = '<?php echo "{$a[\'b\'] ?? 0}";';
        $out = EncapsedCoalesceDesugar::desugar($code);
        $this->assertStringNotContainsString('"{$a', $out);
        $this->assertStringContainsString('$__encapsedCoalesce0 = ($a[\'b\'] ?? 0);', $out);
        $this->assertStringContainsString('echo $__encapsedCoalesce0;', $out);
    }

    public function testDesugarsSuperglobalCoalesce(): void
    {
        $code = '<?php echo "{$_SERVER[\'PHP_SELF\'] ?? \'fallback\'}";';
        $out = EncapsedCoalesceDesugar::desugar($code);
        $this->assertStringContainsString('$__encapsedCoalesce0 = ($_SERVER[\'PHP_SELF\'] ?? \'fallback\');', $out);
        $this->assertStringContainsString('echo $__encapsedCoalesce0;', $out);
    }

    public function testDesugarsMultipleCoalesceInOneString(): void
    {
        $code = '<?php echo "x{$a[\'k1\'] ?? \'1\'}y{$a[\'k2\'] ?? \'2\'}z";';
        $out = EncapsedCoalesceDesugar::desugar($code);
        $this->assertStringContainsString('$__encapsedCoalesce0 = ($a[\'k1\'] ?? \'1\');', $out);
        $this->assertStringContainsString('$__encapsedCoalesce1 = ($a[\'k2\'] ?? \'2\');', $out);
        $this->assertStringContainsString("'x' . \$__encapsedCoalesce0 . 'y' . \$__encapsedCoalesce1 . 'z'", $out);
    }

    public function testDesugarsNullsafeWithCoalesce(): void
    {
        $code = '<?php echo "{$obj?->prop ?? \'def\'}";';
        $out = EncapsedCoalesceDesugar::desugar($code);
        $this->assertStringContainsString('$__encapsedCoalesce0 = ($obj?->prop ?? \'def\');', $out);
        $this->assertStringContainsString('echo $__encapsedCoalesce0;', $out);
    }

    public function testDesugarsDollarCurlySimpleVarCoalesce(): void
    {
        $code = '<?php echo "${name ?? \'world\'}";';
        $out = EncapsedCoalesceDesugar::desugar($code);
        $this->assertStringContainsString('$__encapsedCoalesce0 = ($name ?? \'world\');', $out);
        $this->assertStringContainsString('echo $__encapsedCoalesce0;', $out);
    }

    public function testDesugarsDollarCurlyArrayDimCoalesce(): void
    {
        $code = '<?php echo "${arr[\'k\'] ?? \'missing\'}";';
        $out = EncapsedCoalesceDesugar::desugar($code);
        $this->assertStringContainsString('$__encapsedCoalesce0 = ($arr[\'k\'] ?? \'missing\');', $out);
        $this->assertStringContainsString('echo $__encapsedCoalesce0;', $out);
    }

    public function testDesugarsDollarAndBraceCoalesceInOneString(): void
    {
        $code = '<?php echo "${name ?? \'a\'}{$other ?? \'b\'}";';
        $out = EncapsedCoalesceDesugar::desugar($code);
        $this->assertStringContainsString('$__encapsedCoalesce0 = ($name ?? \'a\');', $out);
        $this->assertStringContainsString('$__encapsedCoalesce1 = ($other ?? \'b\');', $out);
        $this->assertStringContainsString('$__encapsedCoalesce0 . $__encapsedCoalesce1', $out);
    }

    public function testNoOpDollarCurlyWithoutCoalesce(): void
    {
        $code = '<?php echo "${name}";';
        $this->assertSame($code, EncapsedCoalesceDesugar::desugar($code));
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
