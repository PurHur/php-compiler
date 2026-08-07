<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\VM\EnumCaseSupport::typeNameForVariable */
final class EnumCaseTypeNameTest extends TestCase
{
    public function testEnumCaseObjectUsesEnumClassName(): void
    {
        $enum = new ClassEntry('E');
        $enum->isEnum = true;
        $enum->backedType = 'string';
        $backing = new Variable(Variable::TYPE_STRING);
        $backing->string('x');
        $case = EnumCaseSupport::createCase($enum, 'A', $backing);

        $this->assertSame('E', EnumCaseSupport::typeNameForVariable($case));
    }

    public function testEnumCaseEntryUsesEnumClassName(): void
    {
        $enum = new ClassEntry('Status');
        $enum->isEnum = true;
        $backing = new Variable();
        $backing->null();
        $var = new Variable(Variable::TYPE_ENUM_CASE);
        $var->enumCase(new \PHPCompiler\VM\EnumCaseEntry($enum, 'Active', $backing));

        $this->assertSame('Status', EnumCaseSupport::typeNameForVariable($var));
    }

    public function testIterableCheckRejectsEnumCaseWithClassName(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $enum = new ClassEntry('E');
        $enum->isEnum = true;
        $enum->backedType = 'string';
        $backing = new Variable(Variable::TYPE_STRING);
        $backing->string('x');
        $case = EnumCaseSupport::createCase($enum, 'A', $backing);

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Traversable|array');
        $this->expectExceptionMessage('E given');
        IterableCheck::assertParameter($case, $ctx);
    }

    /** @covers \PHPCompiler\VM\EnumCaseSupport::formatIllegalContainerOffsetMessage */
    public function testFormatIllegalContainerOffsetMessageProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsTypedIllegalContainerOffset());
            $this->assertSame(
                'Cannot access offset of type E on array',
                EnumCaseSupport::formatIllegalContainerOffsetMessage('E')
            );
            $this->assertSame(
                'Cannot access offset of type S on array',
                EnumCaseSupport::formatIllegalContainerOffsetMessage('S', 'Illegal offset type')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers \PHPCompiler\VM\EnumCaseSupport::illegalArrayOffsetMessage */
    public function testIllegalArrayOffsetMessageForEnumCaseObjectProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $enum = new ClassEntry('E');
            $enum->isEnum = true;
            $backing = new Variable();
            $backing->null();
            $case = EnumCaseSupport::createCase($enum, 'A', $backing);
            $this->assertSame(
                'Cannot access offset of type E on array',
                EnumCaseSupport::illegalArrayOffsetMessage($case)
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
