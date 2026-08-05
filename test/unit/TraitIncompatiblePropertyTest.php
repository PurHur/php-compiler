<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Incompatible trait property runtime fatal (#7418, #11834, #17995, Zend/zend_traits.c). */
final class TraitIncompatiblePropertyTest extends TestCase
{
    public function testIncompatibleTraitStaticPropertiesZendMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public static int $x = 1; }
trait U { public static int $x = 2; }
class C { use T, U; }
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_incompatible_static_properties.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  T and U define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->run($block, false);
    }

    public function testIncompatibleTraitInstancePropertiesZendMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public int $x = 1; }
trait U { public int $x = 2; }
class C { use T, U; }
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_incompatible_instance_properties.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  T and U define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->run($block, false);
    }

    public function testIncompatibleClassTraitInstancePropertiesZendMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public $x = 1; }
class C { use T; public $x = 2; }
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_class_incompatible_instance_properties.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  C and T define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->run($block, false);
    }

    public function testIdenticalClassTraitInstancePropertiesMerge(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public $x = 1; }
class C { use T; public $x = 1; }
echo (new C)->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_class_identical_instance_properties.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block, false);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testIdenticalTwoTraitInstancePropertiesMerge(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public $x = 1; }
trait U { public $x = 1; }
class C { use T, U; }
echo (new C)->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_trait_identical_instance_properties.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block, false);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testIdenticalClassTraitStaticPropertiesMerge(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public static $x = 1; }
class C { use T; public static $x = 1; }
echo C::$x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_class_identical_static_properties.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block, false);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testIncompatibleClassTraitStaticPropertiesZendMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public static $x = 1; }
class C { use T; public static $x = 2; }
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_class_incompatible_static_properties.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  C and T define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->run($block, false);
    }

    public function testIncompatibleTraitPropertyParseAndCompileSucceeds(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public $x = 1; }
class C { use T; public $x = 2; }
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_class_incompatible_instance_properties.php');
        $this->assertNotNull($block);
    }
}
