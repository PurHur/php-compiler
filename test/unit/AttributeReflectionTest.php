<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #1936 */
#[Group('AttributeReflection')]
final class AttributeReflectionTest extends TestCase
{
    public function testReflectionClassGetAttributes(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\AllowDynamicProperties]
class Box {}
$rc = new ReflectionClass(Box::class);
$attrs = $rc->getAttributes();
echo count($attrs);
echo "\n";
echo $attrs[0]->getName();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attr_reflection_class.php'));
        $this->assertSame("1\nAllowDynamicProperties", ob_get_clean());
    }

    public function testReflectionMethodGetAttributes(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {
    #[\Deprecated]
    public function ping(): void {}
}
$rm = (new ReflectionClass(Box::class))->getMethod('ping');
$attrs = $rm->getAttributes();
echo count($attrs);
echo "\n";
echo $attrs[0]->getName();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attr_reflection_method.php'));
        $this->assertSame("1\nDeprecated", ob_get_clean());
    }

    public function testAttributeNamesExtractedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
#[\AllowDynamicProperties]
class C {
    #[\Deprecated]
    public function m(): void {}
}
PHP,
            'meta.php'
        );
        $this->assertNotNull($block);
        foreach ($block->opCodes as $op) {
            if (\PHPCompiler\OpCode::TYPE_DECLARE_CLASS === $op->type) {
                $this->assertSame(['AllowDynamicProperties'], $op->attributeNames);

                return;
            }
        }
        $this->fail('TYPE_DECLARE_CLASS not found');
    }
}
