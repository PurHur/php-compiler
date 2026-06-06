<?php

declare(strict_types=1);

use PHPCompiler\Ast\ExitTwoArgDesugar;
use PHPUnit\Framework\TestCase;

/** @covers issue #6718 */
final class ExitTwoArgDesugarTest extends TestCase
{
    public function testDesugarsExitTwoArgCall(): void
    {
        $out = ExitTwoArgDesugar::desugar('<?php exit(1, "bye");');
        $this->assertStringContainsString('__phpcExitTwo(1, "bye")', $out);
        $this->assertStringNotContainsString('exit(1,', $out);
    }

    public function testDesugarsDieTwoArgCall(): void
    {
        $out = ExitTwoArgDesugar::desugar('<?php die(0, "ok");');
        $this->assertStringContainsString('__phpcExitTwo(0, "ok")', $out);
    }

    public function testLeavesSingleArgExitUntouched(): void
    {
        $code = '<?php exit(0); exit("msg");';
        $this->assertSame($code, ExitTwoArgDesugar::desugar($code));
    }
}
