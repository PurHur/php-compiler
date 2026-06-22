<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #4121 */
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
}
