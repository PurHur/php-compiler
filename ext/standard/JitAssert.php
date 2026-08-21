<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\AssertFail;
use PHPCompiler\JIT\Builtin\AssertIniRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for assert() via AssertFail runtime (issues #3157, #6550, #33237).
 *
 * Always {@see AssertFail::ensureLinked} before `__compiler_assert_fail` lookup —
 * Type no longer always-declares that ABI (#33237).
 */
final class JitAssert
{
    /** @return Value int64 1 on pass, 0 on fail (php-cfg infers assert() as int) */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('assert() requires one or two arguments');
        }

        AssertIniRuntime::ensureGlobals($context);

        $boolval = new boolval();
        $truthy = $boolval->call($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');

        $inactiveBlock = BasicBlockHelper::append($context, 'assert_inactive');
        $checkFailBlock = BasicBlockHelper::append($context, 'assert_check_fail');
        $passBlock = BasicBlockHelper::append($context, 'assert_pass');
        $failBlock = BasicBlockHelper::append($context, 'assert_fail');
        $doneBlock = BasicBlockHelper::append($context, 'assert_done');

        $enabled = AssertIniRuntime::loadAssertionsEnabled($context);
        $context->builder->branchIf($enabled, $checkFailBlock, $inactiveBlock);

        $context->builder->positionAtEnd($inactiveBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($checkFailBlock);
        $context->builder->branchIf($truthy, $passBlock, $failBlock);

        $context->builder->positionAtEnd($passBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitFail($context, $args, $argc);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($i64);
        $one = $i64->constInt(1, false);
        $zero = $i64->constInt(0, false);
        $result->addIncoming($one, $inactiveBlock);
        $result->addIncoming($one, $passBlock);
        $result->addIncoming($zero, $failBlock);

        return $result;
    }

    /** @param list<JITVariable> $args */
    private static function emitFail(Context $context, array $args, int $argc): void
    {
        AssertFail::ensureLinked($context);
        if (2 === $argc) {
            if (self::rejectDescriptionEnumCase($context, $args[1])) {
                return;
            }
            $literal = JitStringArg::compileTimeLiteral($args[1]);
            if (null !== $literal) {
                self::emitFailCstr($context, $literal);

                return;
            }
            if (\in_array($args[1]->type, [JITVariable::TYPE_STRING, JITVariable::TYPE_VALUE], true)) {
                $strPtr = JitStringArg::lower($context, $args[1], 'assert() description');
                $context->builder->call(
                    $context->lookupFunction('__compiler_assert_fail_string'),
                    $strPtr
                );

                return;
            }
            throw new \LogicException(
                'assert() description must be a string in this compiler build'
            );
        }
        self::emitFailCstr($context, 'assert(): assert(false) failed');
    }

    /** @return bool true when compile-time enum rejection was emitted (caller must stop) */
    private static function rejectDescriptionEnumCase(Context $context, JITVariable $arg): bool
    {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            self::emitDescriptionTypeError($context, $enumLabel);

            return true;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitRuntimeDescriptionEnumCaseGuard($context, $arg);
        }

        return false;
    }

    private static function emitRuntimeDescriptionEnumCaseGuard(Context $context, JITVariable $arg): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $okBlock = BasicBlockHelper::append($context, 'assert_desc_ok');
        $rejectBlock = BasicBlockHelper::append($context, 'assert_desc_enum');
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $context->builder->branchIf($isEnumCase, $rejectBlock, $okBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitDescriptionTypeError($context, JitOperandTypeLabel::givenLabel($context, $arg));
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitDescriptionTypeError(Context $context, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                'assert(): Argument #2 ($description) must be of type string|Throwable, %s given',
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitFailCstr(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $context->builder->call(
            $context->lookupFunction('__compiler_assert_fail'),
            $msgPtr,
            $msgLen
        );
    }
}
