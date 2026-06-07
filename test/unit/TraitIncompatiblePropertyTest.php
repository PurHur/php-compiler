<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Incompatible trait property fatal message (#7418, Zend/zend_traits.c). */
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
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'T and U define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->run($runtime->parseAndCompile($code, 'trait_incompatible_static_properties.php'));
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
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'T and U define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->run($runtime->parseAndCompile($code, 'trait_incompatible_instance_properties.php'));
    }
}
