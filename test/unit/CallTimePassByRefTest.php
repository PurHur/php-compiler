<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5354 — call-time pass-by-reference removed in PHP 8 */
final class CallTimePassByRefTest extends TestCase
{
    public function testFunctionCallAmpersandArgFailsAtParse(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('syntax error, unexpected token "&"');
        $runtime->parseAndCompile('<?php function f($x) {} f(&$a);', 'call_time_ref.php');
    }

    public function testArrayUnshiftAmpersandArgFailsAtParse(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('syntax error, unexpected token "&"');
        $runtime->parseAndCompile('<?php $a = [1]; array_unshift($a, &$a[0]);', 'call_time_ref_unshift.php');
    }

    public function testDeclaredByRefParameterStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php function g(&$x) { $x = 2; } $v = 1; g($v); echo $v;',
            'declared_by_ref.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('2', ob_get_clean());
    }
}
