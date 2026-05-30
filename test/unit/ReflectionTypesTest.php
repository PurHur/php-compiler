<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3355 */
final class ReflectionTypesTest extends TestCase
{
    public function testReflectionFunctionUnionAndIntersectionTypes(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class S {}
interface I {}
function demo(string|null $a, S&I $b): int|false {}
$rp = (new ReflectionFunction('demo'))->getParameters()[0];
$rt = (new ReflectionFunction('demo'))->getReturnType();
echo $rp->getType()::class, "\n";
echo $rt::class, "\n";
echo (string) $rt, "\n";
$rp2 = (new ReflectionFunction('demo'))->getParameters()[1];
echo $rp2->getType()::class, "\n";
echo (string) $rp2->getType(), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_types.php'));
        $this->assertSame(
            "ReflectionUnionType\nReflectionUnionType\nint|false\nReflectionIntersectionType\nS&I\n",
            ob_get_clean()
        );
    }

    public function testReflectionNamedTypeAllowsNullAndIsBuiltin(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int $x): void {}
$type = (new ReflectionFunction('f'))->getParameters()[0]->getType();
echo $type::class, "\n";
echo $type->getName(), "\n";
echo $type->isBuiltin() ? '1' : '0', "\n";
echo $type->allowsNull() ? '1' : '0', "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_named.php'));
        $this->assertSame("ReflectionNamedType\nint\n1\n0\n", ob_get_clean());
    }
}
