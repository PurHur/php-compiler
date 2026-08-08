<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
final class GotoStatementTest extends TestCase
{
    public function testBackwardGotoLoop(): void
    {
        $this->assertSame(
            "3\n",
            $this->runCode(<<<'PHP'
<?php
$i = 0;
start:
$i++;
if ($i < 3) {
    goto start;
}
echo $i, "\n";
PHP
            )
        );
    }

    public function testForwardGotoSkipsStatement(): void
    {
        $this->assertSame(
            "done\n",
            $this->runCode(<<<'PHP'
<?php
goto end;
echo "no\n";
end:
echo "done\n";
PHP
            )
        );
    }

    public function testGotoPastUnreachableLabel(): void
    {
        $this->assertSame(
            "ok\n",
            $this->runCode(<<<'PHP'
<?php
if (false) {
    skip:
}
echo "ok\n";
PHP
            )
        );
    }

    public function testContinueOutsideLoopCompileFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("'continue' not in the 'loop' or 'switch' context");
        $runtime->parseAndCompile("<?php\ncontinue;\n", 'continue_outside_loop.php');
    }

    public function testBreakOutsideLoopCompileFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("'break' not in the 'loop' or 'switch' context");
        $runtime->parseAndCompile("<?php\nbreak;\n", 'break_outside_loop.php');
    }

    public function testGotoIntoSwitchCompileError(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("'goto' into loop or switch statement is disallowed");
        $runtime->parseAndCompile(<<<'PHP'
<?php
goto a;
switch (1) {
  case 1:
    a:
    echo "HIT";
}
PHP
            , 'goto_into_switch.php');
    }

    public function testGotoIntoForCompileError(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("'goto' into loop or switch statement is disallowed");
        $runtime->parseAndCompile(<<<'PHP'
<?php
goto a;
for ($i = 0; $i < 1; $i++) {
    a:
    echo "HIT";
}
PHP
            , 'goto_into_for.php');
    }

    private function runCode(string $code): string
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'goto_test.php');
        $this->assertNotNull($block);
        \PHPCompiler\VM\OutputBuffer::reset();
        ob_start();
        $runtime->run($block);

        return (string) ob_get_clean();
    }
}
