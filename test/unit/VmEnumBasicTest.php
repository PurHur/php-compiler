<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\EnumCases;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

final class VmEnumBasicTest extends TestCase
{
    public function testBackedEnumDeclareAndCaseFetch(): void
    {
        $code = <<<'PHP'
<?php
enum Status: string {
    case Active = 'active';
}
echo Status::Active;
echo enum_exists('Status') ? '1' : '0';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_basic.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('active1', $output);
        $ctx = $runtime->vmContext;
        $this->assertInstanceOf(Context::class, $ctx);
        $this->assertTrue(VmReflection::enumExists($ctx, 'Status'));
        $this->assertTrue(isset($ctx->classes['status']));
        $this->assertTrue($ctx->classes['status']->isEnum);
        $this->assertSame('string', $ctx->classes['status']->backedType);
        $active = $ctx->classes['status']->constants['active'] ?? null;
        $this->assertNotNull($active);
        $this->assertSame(Variable::TYPE_OBJECT, $active->type);
        $this->assertSame('active', $active->toString());
        $this->assertSame('active', $active->toObject()->getProperty('value')->toString());
        $this->assertSame('Active', $active->toObject()->getProperty('name')->toString());
        $this->assertFalse(VmReflection::classExists($ctx, 'Status'));
    }

    public function testEnumCasesBackedAndUnit(): void
    {
        $code = <<<'PHP'
<?php
enum Suit: string {
    case Hearts = 'H';
    case Diamonds = 'D';
}
enum Status {
    case Pending;
    case Done;
}
$cases = Suit::cases();
echo count($cases);
echo $cases[0]->name;
echo $cases[1]->value;
$unit = Status::cases();
echo count($unit);
echo $unit[0]->name;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_cases.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('2HeartsD2Pending', $output);
        $ctx = $runtime->vmContext;
        $this->assertInstanceOf(Context::class, $ctx);
        $this->assertCount(2, $ctx->classes['suit']->enumCases);
        $this->assertSame('Hearts', $ctx->classes['suit']->enumCases[0]['name']);
        $this->assertSame('D', $ctx->classes['suit']->enumCases[1]['value']->toString());
    }

    public function testBackedEnumCasesCallUnpack(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
function names(...$args): void {
    foreach ($args as $case) {
        if (!$case instanceof E) {
            echo '?';
            continue;
        }
        echo $case->name;
    }
}
names(...E::cases());
names(...[E::A, E::B]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_cases_call_unpack.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('ABAB', $output);
    }

    public function testBackedEnumCasesSpread(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
echo count(E::cases());
echo count([...E::cases()]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_cases_spread.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('22', $output);
    }

    /** Folded compile-time enum case must satisfy instanceof UnitEnum/BackedEnum (#5711). */
    public function testEnumCaseInstanceofBuiltinEnumInterfaces(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; }
echo (E::A instanceof UnitEnum) ? '1' : '0';
echo (E::A instanceof BackedEnum) ? '1' : '0';
echo (E::A instanceof E) ? '1' : '0';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_instanceof_builtin.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('111', $output);
    }

    /** Enum::cases() must return canonical singletons (Zend zend_enum_list_cases, #5715). */
    public function testEnumCasesIdentity(): void
    {
        $code = <<<'PHP'
<?php
enum U { case A; case B; }
enum E: int { case A = 1; case B = 2; }
$cases = U::cases();
echo ($cases[0] instanceof U) ? '1' : '0';
echo ($cases[0] === U::A) ? '1' : '0';
$backed = E::cases();
echo ($backed[0] === E::A) ? '1' : '0';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_cases_identity.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('111', $output);

        $ctx = $runtime->vmContext;
        $this->assertInstanceOf(Context::class, $ctx);
        $unitEntry = $ctx->classes['u'];
        $backedEntry = $ctx->classes['e'];
        $unitA = $unitEntry->constants['a']->resolveIndirect();
        $backedA = $backedEntry->constants['a']->resolveIndirect();
        $this->assertSame(Variable::TYPE_OBJECT, $unitA->type);
        $this->assertSame(Variable::TYPE_OBJECT, $backedA->type);

        $returnVar = new Variable();
        $frame = new Frame(null, $block, null);
        $frame->returnVar = $returnVar;
        (new EnumCases($unitEntry))->execute($frame);
        $uCase0 = $returnVar->toArray()->findIndex(0);
        $this->assertNotNull($uCase0);
        $uCase0 = $uCase0->resolveIndirect();
        $this->assertSame(Variable::TYPE_OBJECT, $uCase0->type);
        $this->assertSame($unitA->toObject(), $uCase0->toObject());

        $returnVar = new Variable();
        $frame = new Frame(null, $block, null);
        $frame->returnVar = $returnVar;
        (new EnumCases($backedEntry))->execute($frame);
        $eCase0 = $returnVar->toArray()->findIndex(0);
        $this->assertNotNull($eCase0);
        $eCase0 = $eCase0->resolveIndirect();
        $this->assertSame(Variable::TYPE_OBJECT, $eCase0->type);
        $this->assertSame($backedA->toObject(), $eCase0->toObject());
    }
}
