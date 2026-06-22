<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** ReflectionEnumCase::getValue() returns enum case object (#9537, php_reflection.c). */
#[Group('ReflectionEnum')]
final class ReflectionEnumCaseGetValueTest extends TestCase
{
    public function testGetValueReturnsEnumCaseObject(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; case B = 2; }
$c = (new ReflectionEnum(E::class))->getCase('A');
var_export($c->getValue());
echo "\n";
var_export($c->getBackingValue());
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_enum_case_get_value.php'));
        $this->assertSame("\\E::A\n1\n", ob_get_clean());
    }
}
