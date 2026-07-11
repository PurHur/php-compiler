<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #3354 */
#[Group('ReflectionOop')]
final class ReflectionOopTest extends TestCase
{
    public function testReflectionPropertyFunctionConstant(): void
    {
        if (!CompilerVersion::advertisesReflectionConstantClass()) {
            $this->markTestSkipped('ReflectionConstant not advertised on reference profile');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C { public int $x = 1; public const FOO = 42; }
function f(): void {}

$r1 = new ReflectionProperty(C::class, 'x');
echo $r1->getName(), $r1->getValue(new C());

$r2 = new ReflectionFunction('f');
echo $r2->getName();

$r3 = new ReflectionConstant(C::class, 'FOO');
echo $r3->getValue();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_oop.php'));
        $this->assertSame('x1f42', ob_get_clean());
    }
}
