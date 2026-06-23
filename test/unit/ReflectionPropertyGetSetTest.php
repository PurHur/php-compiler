<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #4469 */
#[Group('ReflectionPropertyGetSet')]
final class ReflectionPropertyGetSetTest extends TestCase
{
    public function testGetValueSetValueStaticAndInstance(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public static int $stat = 10;
    public int $x = 1;
}

$rpS = new ReflectionProperty(C::class, 'stat');
$rpX = new ReflectionProperty(C::class, 'x');

var_dump($rpS->getValue());
var_dump($rpX->getValue(new C()));

try {
    $rpX->getValue();
} catch (TypeError $e) {
    echo 'missing obj: TypeError', "\n";
}

$c = new C();
$rpX->setValue($c, 99);
var_dump($c->x);

$rpS->setValue(55);
var_dump($rpS->getValue());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_property_get_set.php'));
        $this->assertSame(
            "int(10)\nint(1)\nmissing obj: TypeError\nint(99)\nint(55)\n",
            ob_get_clean()
        );
    }
}
