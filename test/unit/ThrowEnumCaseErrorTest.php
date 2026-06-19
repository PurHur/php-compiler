<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseEntry;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** throw enum case — Zend message on VM + JIT (#9662, zend_exceptions.c). */
final class ThrowEnumCaseErrorTest extends TestCase
{
    public function testThrowNormalizeErrorMessageForEnumCase(): void
    {
        $enum = new ClassEntry('E');
        $enum->isEnum = true;
        $enum->backedType = 'int';
        $backing = new Variable();
        $backing->int(1);
        $var = new Variable(Variable::TYPE_ENUM_CASE);
        $var->enumCase(new EnumCaseEntry($enum, 'A', $backing));

        $this->assertSame(
            ExceptionSupport::THROW_NON_THROWABLE_MESSAGE,
            ExceptionSupport::throwNormalizeErrorMessage($var)
        );
    }

    public function testTryCatchHelperJitThrowGuardsEnumCaseOperand(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/TryCatchHelper.php');
        $this->assertStringContainsString('Variable::TYPE_ENUM_CASE === $thrown->type', $source);
        $this->assertStringContainsString('ExceptionSupport::THROW_NON_THROWABLE_MESSAGE', $source);
    }
}
