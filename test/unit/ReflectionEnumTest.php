<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #4121 #25940 */
#[Group('ReflectionEnum')]
final class ReflectionEnumTest extends TestCase
{
    public function testBackedEnumReflection(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
}
$r = new ReflectionEnum(Suit::class);
echo $r->getName(), "\n";
echo $r->isBacked() ? "backed\n" : "unit\n";
foreach ($r->getCases() as $case) {
    echo $case->getName(), "\n";
}
$hearts = $r->getCase('Hearts');
echo $hearts->getName(), "\n";
var_export($hearts->getValue());
echo "\n";
echo class_exists('ReflectionEnum') ? "exists\n" : "missing\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_enum.php'));
        $this->assertSame(
            "Suit\nbacked\nHearts\nSpades\nHearts\n\\Suit::Hearts\nexists\n",
            ob_get_clean()
        );
    }

    /** Case-sensitive enum case keys after #25910/#25929 — getCase/getCases must not strtolower (#25940). */
    public function testGetCasesAndGetCaseMatchHasCase(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
$r = new ReflectionEnum(E::class);
echo var_export($r->hasCase('A'), true), "\n";
foreach ($r->getCases() as $c) {
    echo $c->getName(), ':', $c->getBackingValue(), "\n";
}
echo $r->getCase('A')->getName(), "\n";
enum U { case X; }
echo (new ReflectionEnum(U::class))->getCase('X')->getName(), "\n";
try {
    (new ReflectionEnum(E::class))->getCase('Z');
    echo "missing_ok\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_enum_getcases_25940.php'));
        $this->assertSame(
            "true\nA:1\nA\nX\nCase E::Z does not exist\n",
            ob_get_clean()
        );
    }
}
