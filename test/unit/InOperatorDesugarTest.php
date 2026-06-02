<?php

declare(strict_types=1);

use PHPCompiler\Ast\InOperatorDesugar;
use PHPUnit\Framework\TestCase;

/** @group compliance */
final class InOperatorDesugarTest extends TestCase
{
    public function testDesugarsEnumInArrayLiteral(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; case B = 'b'; }
$e = E::A;
var_dump($e in [E::A, E::B]);
PHP;
        $out = InOperatorDesugar::desugar($code);
        $this->assertStringContainsString('__phpcLangIn($e, [E::A, E::B])', $out);
        $this->assertStringNotContainsString(' in ', $out);
    }
}
