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
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\StringOffsetJitHelper;
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

    /** @covers \PHPCompiler\VM\EnumCaseSupport::typeNameForVariable */
    public function testResourceObjectUsesLowercaseResourceLabel(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $var = new Variable();
        ResourceSupport::wrap($var, 1, ResourceState::KIND_STREAM, $ctx);

        $this->assertSame('resource', EnumCaseSupport::typeNameForVariable($var));
        $this->assertSame(
            'Cannot access offset of type resource on string',
            StringOffsetJitHelper::illegalDimTypeErrorMessage(
                EnumCaseSupport::typeNameForVariable($var)
            )
        );
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

    /**
     * @covers \PHPCompiler\VM\EnumCaseSupport::classPseudoConstTypeErrorMessage
     * @covers issue #29576 / #29592
     */
    public function testClassPseudoConstTypeErrorMessageProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $this->assertTrue(CompilerVersion::supportsClassPseudoConstValueNameTypeError());
            $s = new Variable(Variable::TYPE_STRING);
            $s->string('stdClass');
            $this->assertSame(
                'Cannot use "::class" on string',
                EnumCaseSupport::classPseudoConstTypeErrorMessage($s)
            );
            // #29592 / #30054 — bool uses concrete true/false, not "bool"
            $t = new Variable(Variable::TYPE_BOOLEAN);
            $t->bool(true);
            $this->assertSame('true', EnumCaseSupport::typeNameForTypeErrorActual($t));
            $this->assertSame(
                'Cannot use "::class" on true',
                EnumCaseSupport::classPseudoConstTypeErrorMessage($t)
            );
            $f = new Variable(Variable::TYPE_BOOLEAN);
            $f->bool(false);
            $this->assertSame('false', EnumCaseSupport::typeNameForTypeErrorActual($f));
            $this->assertSame(
                'Cannot use "::class" on false',
                EnumCaseSupport::classPseudoConstTypeErrorMessage($f)
            );
            $this->assertSame(
                'Cannot use "::class" on int',
                EnumCaseSupport::formatClassPseudoConstTypeErrorMessage('int')
            );
            // #29623 — Resource wrappers use lowercase "resource", not ClassEntry "Resource"
            $ctx = new Context(new Runtime());
            BuiltinClasses::register($ctx);
            $res = new Variable();
            ResourceSupport::wrap($res, 1, ResourceState::KIND_STREAM, $ctx);
            $this->assertSame(
                'Cannot use "::class" on resource',
                EnumCaseSupport::classPseudoConstTypeErrorMessage($res)
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }

    /** @covers \PHPCompiler\VM\EnumCaseSupport::classPseudoConstTypeErrorMessage */
    public function testClassPseudoConstTypeErrorMessageDefaultLegacy(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        try {
            $this->assertFalse(CompilerVersion::supportsClassPseudoConstValueNameTypeError());
            $s = new Variable(Variable::TYPE_STRING);
            $s->string('stdClass');
            $this->assertSame(
                'Cannot use "::class" on value of type string',
                EnumCaseSupport::classPseudoConstTypeErrorMessage($s)
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
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
