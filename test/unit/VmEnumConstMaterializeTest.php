<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * const/define/class const must store enum case objects, not backing scalars (#5738).
 */
final class VmEnumConstMaterializeTest extends TestCase
{
    public function testFileClassAndDefineConstantsMaterializeEnumCase(): void
    {
        $code = <<<'PHP'
<?php
enum E: string {
    case A = 'x';
}
const FILE_C = E::A;
class C {
    public const CLASS_C = E::A;
}
define('DEFINE_C', E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_const_materialize.php');
        $runtime->run($block);
        $ctx = $runtime->vmContext;

        foreach (['FILE_C', 'DEFINE_C'] as $name) {
            $this->assertArrayHasKey($name, $ctx->constants);
            $this->assertTrue(
                EnumCaseSupport::isEnumCaseVariable($ctx->constants[$name]),
                $name.' must be enum case object'
            );
        }
        $this->assertTrue(isset($ctx->classes['c']));
        $classConst = $ctx->classes['c']->constants['class_c'];
        $this->assertTrue(EnumCaseSupport::isEnumCaseVariable($classConst));
    }

    public function testMaterializeConstantValueUpgradesLegacyBackingScalar(): void
    {
        $code = <<<'PHP'
<?php
enum E: string {
    case A = 'x';
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_legacy_scalar.php');
        $runtime->run($block);
        $enum = $runtime->vmContext->classes['e'];
        $scalar = new Variable(Variable::TYPE_STRING);
        $scalar->string('x');
        $enum->constants['a'] = $scalar;

        $materialized = EnumCaseSupport::materializeConstantValue($runtime->vmContext, $scalar);
        $this->assertTrue(EnumCaseSupport::isEnumCaseVariable($materialized));
        $this->assertSame('A', $materialized->toObject()->enumCaseName);
    }
}
