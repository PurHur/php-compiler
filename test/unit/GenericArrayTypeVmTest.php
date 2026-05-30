<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPCompiler\GenericArrayTypeSourceRewriter;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

final class GenericArrayTypeVmTest extends TestCase
{
    public function testListParameterRejectsAssociativeArray(): void
    {
        $code = GenericArrayTypeSourceRewriter::rewrite(<<<'PHP'
<?php
function f(list $x): void {
}
f(['a' => 1]);
PHP);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'list_reject.php');
        $this->assertNotNull($block);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('list');
        $runtime->run($block);
    }

    public function testListParameterAcceptsPackedList(): void
    {
        $code = GenericArrayTypeSourceRewriter::rewrite(<<<'PHP'
<?php
function f(list $x): int {
    return count($x);
}
echo f([1, 2, 3]);
PHP);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'list_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $exit = $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame(VM::SUCCESS, $exit);
        $this->assertSame('3', $out);
    }
}
