<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Incompatible trait property fatal message (#7418, #11834, Zend/zend_traits.c). */
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
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'T and U define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->parseAndCompile($code, 'trait_incompatible_static_properties.php');
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
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'T and U define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->parseAndCompile($code, 'trait_incompatible_instance_properties.php');
    }

    public function testIncompatibleClassTraitInstancePropertiesZendMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public $x = 1; }
class C { use T; public $x = 2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'C and T define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->parseAndCompile($code, 'trait_class_incompatible_instance_properties.php');
    }

    public function testIncompatibleClassTraitStaticPropertiesZendMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public static $x = 1; }
class C { use T; public static $x = 2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'C and T define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->parseAndCompile($code, 'trait_class_incompatible_static_properties.php');
    }
}
