<?php

declare(strict_types=1);

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Issue #31158 — enum case `in` array is a Parse error (php-src has no `in` operator). */
final class VmEnumInOperatorTest extends TestCase
{
    public function testBackedEnumCaseInArrayIsParseError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; case B = 'b'; }
$e = E::A;
var_dump($e in [E::A, E::B]);
var_dump($e in [E::B]);
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected identifier "in"');
        $runtime->parseAndCompile($code, 'enum_in.php');
    }
}
