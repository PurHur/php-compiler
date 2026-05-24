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
