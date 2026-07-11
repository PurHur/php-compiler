<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #9886 */
#[Group('ReflectionEnum')]
final class ReflectionEnumGetBackingTypeTest extends TestCase
{
    public function testBackedIntEnumReturnsReflectionNamedType(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
$r = new ReflectionEnum(E::class);
$type = $r->getBackingType();
echo $type::class, "\n";
echo $type->getName(), "\n";
echo $type->isBuiltin() ? "1\n" : "0\n";
echo $r->isBacked() ? "1\n" : "0\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_enum_backing.php'));
        $this->assertSame("ReflectionNamedType\nint\n1\n1\n", ob_get_clean());
    }

    public function testUnitEnumReturnsNull(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum U { case A; case B; }
$r = new ReflectionEnum(U::class);
echo null === $r->getBackingType() ? "null\n" : "not-null\n";
echo $r->isBacked() ? "1\n" : "0\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_enum_unit.php'));
        $this->assertSame("null\n0\n", ob_get_clean());
    }
}
