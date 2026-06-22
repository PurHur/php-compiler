<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #3800 */
#[Group('ReflectionEnumUnitCase')]
final class ReflectionEnumUnitCaseTest extends TestCase
{
    public function testEnumCaseAttributesAndNewInstance(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class A {
    public function __construct(public string $v) {}
}
enum E: int {
    #[A("marker")]
    case One = 1;
}
$ref = new ReflectionEnumUnitCase(E::class, "One");
echo $ref->getAttributes()[0]->newInstance()->v, "\n";
echo $ref->getName(), "\n";
var_export($ref->getValue());
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_enum_unit_case.php'));
        $this->assertSame("marker\nOne\n\\E::One\n", ob_get_clean());
    }

    public function testEnumCaseAttributeMetadataAtCompileTime(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
#[Attribute]
class A {}
enum E: int {
    #[A("x")]
    case One = 1;
}
PHP,
            'meta.php'
        );
        $this->assertNotNull($block);
        foreach ($block->opCodes as $op) {
            if (\PHPCompiler\OpCode::TYPE_DECLARE_ENUM !== $op->type) {
                continue;
            }
            foreach ($op->block1->opCodes as $inner) {
                if (\PHPCompiler\OpCode::TYPE_DECLARE_CLASS_CONST !== $inner->type) {
                    continue;
                }
                $this->assertSame(['A'], $inner->attributeNames);
                $this->assertCount(1, $inner->attributeEntries);
                $this->assertSame('A', $inner->attributeEntries[0]->name);
                $this->assertCount(1, $inner->attributeEntries[0]->args);
                $this->assertSame('x', $inner->attributeEntries[0]->args[0]['value']);

                return;
            }
        }
        $this->fail('TYPE_DECLARE_CLASS_CONST for enum case not found');
    }
}
