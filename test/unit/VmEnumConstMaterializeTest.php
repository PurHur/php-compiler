<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
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

    public function testDetachConstantValuePreservesEnumCaseMetadata(): void
    {
        $code = <<<'PHP'
<?php
enum E: string {
    case A = 'a';
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_detach_case.php');
        $runtime->run($block);
        $enum = $runtime->vmContext->classes['e'];
        $canonical = $enum->constants['a'];
        $this->assertTrue(EnumCaseSupport::isEnumCaseVariable($canonical));

        $detached = \PHPCompiler\VM\ClassConstMaterializer::detachConstantValue($canonical);
        $this->assertTrue(EnumCaseSupport::isEnumCaseVariable($detached));
        $object = $detached->toObject();
        $this->assertSame('A', $object->enumCaseName);
        $this->assertSame('a', $object->enumCaseValue?->toString());
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

    public function testMaterializeConstantValueUpgradesLegacyBackingScalarsInsideArray(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case X = 1;
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_array_scalar.php');
        $runtime->run($block);
        $enum = $runtime->vmContext->classes['e'];
        $scalar = new Variable(Variable::TYPE_INTEGER);
        $scalar->int(1);
        $enum->constants['x'] = $scalar;

        $ht = new HashTable();
        $ht->append($scalar);
        $arr = new Variable(Variable::TYPE_ARRAY);
        $arr->array($ht);

        $materialized = EnumCaseSupport::materializeConstantValue($runtime->vmContext, $arr);
        $this->assertTrue($materialized->is(Variable::TYPE_ARRAY));
        $idx = new Variable(Variable::TYPE_INTEGER);
        $idx->int(0);
        $elem = $materialized->toArray()->findVariable($idx, false);
        $this->assertNotNull($elem);
        $this->assertTrue(EnumCaseSupport::isEnumCaseVariable($elem->resolveIndirect()));
    }

    public function testFetchCaseByMemberNamePreservesEnumCaseMetadata(): void
    {
        $code = <<<'PHP'
<?php
enum E: string {
    case A = 'a';
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_fetch_case_metadata.php');
        $runtime->run($block);
        $enum = $runtime->vmContext->classes['e'];

        $dest = new Variable();
        $this->assertTrue(
            EnumCaseSupport::fetchCaseByMemberName($enum, 'a', $dest, $runtime->vmContext)
        );
        $this->assertTrue(EnumCaseSupport::isEnumCaseVariable($dest));
        $object = $dest->resolveIndirect()->toObject();
        $this->assertSame('A', $object->enumCaseName);
        $this->assertNotNull($object->enumCaseValue);
        $this->assertSame('a', $object->enumCaseValue->toString());
    }

    public function testClassConstArrayLiteralMaterializesEnumCases(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case X = 1;
    case Y = 2;
}
class C {
    public const AR = [E::X, E::Y];
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'class_const_enum_array.php');
        $runtime->run($block);
        $ar = $runtime->vmContext->classes['c']->constants['ar'];
        $this->assertTrue($ar->is(Variable::TYPE_ARRAY));
        foreach ([0, 1] as $i) {
            $idx = new Variable(Variable::TYPE_INTEGER);
            $idx->int($i);
            $elem = $ar->toArray()->findVariable($idx, false);
            $this->assertNotNull($elem);
            $this->assertTrue(
                EnumCaseSupport::isEnumCaseVariable($elem->resolveIndirect()),
                'AR['.$i.'] must be enum case object'
            );
        }
    }
}
