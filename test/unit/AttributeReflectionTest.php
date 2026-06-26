<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
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

    /** @covers issue #6369 */
    public function testDeprecatedBuiltinAttributeClassExists(): void
    {
        if (!\PHPCompiler\CompilerVersion::advertisesDeprecatedAttributeClass()) {
            $this->markTestSkipped('Deprecated attribute class not advertised on reference profile');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\Deprecated(message: "legacy", since: "8.4")]
class Legacy {}
echo class_exists('Deprecated') ? 'yes' : 'no';
echo "\n";
$attr = (new ReflectionClass(Legacy::class))->getAttributes()[0];
echo $attr->getName(), "\n";
$inst = $attr->newInstance();
echo $inst->message, "\n";
echo $inst->since, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'deprecated_builtin_class.php'));
        $this->assertSame("yes\nDeprecated\nlegacy\n8.4\n", ob_get_clean());
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

    /** @covers issue #3467 */
    public function testAllowDynamicPropertiesPermitsUndeclaredWrites(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\AllowDynamicProperties]
class C {}
$o = new C;
$o->dynamic = 'ok';
echo $o->dynamic;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'allow_dynamic.php'));
        $this->assertSame('ok', ob_get_clean());
    }

    /** @covers issue #3206 */
    public function testReflectionAttributeNewInstance(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class Route {
    public function __construct(public string $path) {}
}
#[Route('/home')]
class C {}
$a = (new ReflectionClass(C::class))->getAttributes()[0];
$o = $a->newInstance();
echo $o->path;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attr_new_instance.php'));
        $this->assertSame('/home', ob_get_clean());
    }

    /** @covers issue #3206 */
    public function testReflectionAttributeNewInstanceReturnsDistinctObjects(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class Route {
    public function __construct(public string $path) {}
}
#[Route('/home')]
class C {}
$a = (new ReflectionClass(C::class))->getAttributes()[0];
$o1 = $a->newInstance();
$o2 = $a->newInstance();
echo ($o1 === $o2) ? 'same' : 'distinct';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attr_new_instance_distinct.php'));
        $this->assertSame('distinct', ob_get_clean());
    }

    /** @covers issue #3206 */
    public function testReflectionAttributeNewInstanceMissingClass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class A {}
#[NonExistentAttribute]
class B {}
$attr = (new ReflectionClass(B::class))->getAttributes()[0];
$attr->newInstance();
PHP;
        try {
            $runtime->run($runtime->parseAndCompile($code, 'attr_missing.php'));
            $this->fail('Expected uncaught Error for missing attribute class');
        } catch (ScriptExit $e) {
            // Zend SAPI fatal — VM maps uncaught Error to ScriptExit (#3206).
            $this->assertSame(255, $e->status);
        }
    }

    /** @covers issue #3467, #3253 */
    public function testPlainClassAllowsUndeclaredWritesWithDeprecation(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class D {}
$d = new D;
$d->x = 1;
echo $d->x;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'no_dynamic.php'));
        $this->assertSame('1', ob_get_clean());
    }
}
