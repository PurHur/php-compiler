<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\phpc_match_unhandled_operand_is_object;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseEntry;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Guard match enum UnhandledMatchError operand probe (#5448, #7199). */
final class MatchUnhandledOperandTest extends \PHPUnit\Framework\TestCase
{
    public function testPhpcMatchUnhandledOperandIsObjectTrueForEnumCase(): void
    {
        $enumClass = new ClassEntry('E');
        $enumClass->isEnum = true;
        $enumClass->backedType = 'int';
        $backing = new Variable(Variable::TYPE_INTEGER);
        $backing->int(1);
        $case = new Variable(Variable::TYPE_ENUM_CASE);
        $case->enumCase(new EnumCaseEntry($enumClass, 'A', $backing));

        $fn = new phpc_match_unhandled_operand_is_object();
        $frame = new Frame($fn, null, null);
        $frame->calledArgs = [$case];
        $out = new Variable();
        $frame->returnVar = $out;
        $fn->execute($frame);

        self::assertTrue($out->resolveIndirect()->toBool());
    }

    public function testPhpcMatchUnhandledOperandIsObjectTrueForObject(): void
    {
        $class = new ClassEntry('C');
        $object = new Variable(Variable::TYPE_OBJECT);
        $object->object(new ObjectEntry($class));

        $fn = new phpc_match_unhandled_operand_is_object();
        $frame = new Frame($fn, null, null);
        $frame->calledArgs = [$object];
        $out = new Variable();
        $frame->returnVar = $out;
        $fn->execute($frame);

        self::assertTrue($out->resolveIndirect()->toBool());
    }

    public function testPhpcMatchUnhandledOperandIsObjectFalseForInt(): void
    {
        $value = new Variable(Variable::TYPE_INTEGER);
        $value->int(2);

        $fn = new phpc_match_unhandled_operand_is_object();
        $frame = new Frame($fn, null, null);
        $frame->calledArgs = [$value];
        $out = new Variable();
        $frame->returnVar = $out;
        $fn->execute($frame);

        self::assertFalse($out->resolveIndirect()->toBool());
    }
}
