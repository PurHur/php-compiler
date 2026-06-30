<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\EncapsedCoalesceRejector;
use PHPUnit\Framework\TestCase;

final class EncapsedCoalesceRejectorTest extends TestCase
{
    public function testRejectsArrayDimCoalesce(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('unexpected token "??"');

        EncapsedCoalesceRejector::reject('<?php echo "{$a[\'b\'] ?? 0}";', 'test.php');
    }

    public function testRejectsSuperglobalCoalesce(): void
    {
        try {
            EncapsedCoalesceRejector::reject('<?php echo "{$_SERVER[\'PHP_SELF\'] ?? \'fallback\'}";', 'test.php');
            $this->fail('expected CompileFatal');
        } catch (CompileFatal $e) {
            $this->assertSame(1, $e->sourceLine);
            $this->assertStringContainsString('unexpected token "??"', $e->getMessage());
        }
    }

    public function testNoOpWithoutCoalesce(): void
    {
        $code = '<?php echo "{$a->p}";';
        $this->assertSame($code, EncapsedCoalesceRejector::reject($code, 'test.php'));
    }

    public function testNoOpSingleQuoted(): void
    {
        $code = "<?php echo 'no ?? here';";
        $this->assertSame($code, EncapsedCoalesceRejector::reject($code, 'test.php'));
    }

    public function testNoOpCoalesceOutsideEncapsed(): void
    {
        $code = '<?php echo $a ?? 0;';
        $this->assertSame($code, EncapsedCoalesceRejector::reject($code, 'test.php'));
    }
}
