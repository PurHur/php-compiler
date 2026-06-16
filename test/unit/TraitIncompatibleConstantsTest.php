<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Incompatible trait constants fatal at compile time (#5385, #8882, Zend/zend_traits.c). */
final class TraitIncompatibleConstantsTest extends TestCase
{
    public function testIncompatibleTraitConstantsZendMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T1 { public const X = 1; }
trait T2 { public const X = 2; }
class C { use T1, T2; }
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(
            'T1 and T2 define the same constant (X) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->parseAndCompile($code, 'trait_incompatible_constants.php');
    }

    public function testClassOverridesTraitConstantWithIncompatibleValue(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public const X = 1; }
class C { use T; public const X = 2; }
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(
            'C and T define the same constant (X) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->parseAndCompile($code, 'trait_const_class_override.php');
    }
}
