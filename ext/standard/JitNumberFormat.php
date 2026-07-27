<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for number_format() (int/float/numeric string, arity 1–4).
 *
 * php-src: ext/standard/number_format.c — Z_PARAM_DOUBLE / Z_PARAM_LONG / Z_PARAM_STR
 */
final class JitNumberFormat
{
    private const MAX_ARGS = 4;

    /**
     * @param JITVariable ...$args
     */
    public static function assertArgCount(Context $context, JITVariable ...$args): void
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'number_format() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc <= self::MAX_ARGS) {
            return;
        }

        throw new \ArgumentCountError(\sprintf(
            'number_format() expects at most %d arguments, %d given',
            self::MAX_ARGS,
            $argc
        ));
    }

    public static function format(Context $context, JITVariable ...$args): Value
    {
        // User-standalone init skips StringFormat::ensureLinked (#13571) —
        // link __compiler_number_format on first call-site lowering (#15642, #18525).
        if ('1' !== getenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING')) {
            \PHPCompiler\JIT\Builtin\StringFormat::implementIfDeclared($context, true);
        }

        $argc = \count($args);

        // strict_types only — PROFILE=8.4 still soft-nulls like Zend Z_PARAM_DOUBLE (#21429).
        self::rejectNullNum($context, $args[0]);

        $number = JitFdiv::lowerSingleOperand(
            $context,
            $args[0],
            1,
            'num',
            'number_format',
            'float',
            false
        );
        $i64 = $context->getTypeFromString('int64');
        $decimals = ($argc >= 2 && !NamedOptionalCallArgs::isOmittedOptional($args[1]))
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'number_format', 2, 'decimals')
            : $i64->constInt(0, false);
        if (version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')) {
            $negative = $context->builder->icmp(Builder::INT_SLT, $decimals, $i64->constInt(0, false));
            $okBlock = BasicBlockHelper::append($context, 'number_format_decimals_ok');
            $failBlock = BasicBlockHelper::append($context, 'number_format_decimals_fail');
            $context->builder->branchIf($negative, $failBlock, $okBlock);
            $context->builder->positionAtEnd($failBlock);
            $message = 'number_format(): Argument #2 ($decimals) must be greater than or equal to 0';
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitValueError($context, $message);
            if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
            } else {
                $context->builder->call($context->lookupFunction('abort'));
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }
            $context->builder->positionAtEnd($okBlock);
        }
        $decSep = ($argc >= 3 && !NamedOptionalCallArgs::isOmittedOptional($args[2]))
            ? JitStringBuiltinArg::lower($context, $args[2], 'number_format', 2, 'decimal_separator', '?string')
            : $context->builder->load($context->constantStringFromString('.'));
        $thouSep = ($argc >= 4 && !NamedOptionalCallArgs::isOmittedOptional($args[3]))
            ? JitStringBuiltinArg::lower($context, $args[3], 'number_format', 3, 'thousands_separator', '?string')
            : $context->builder->load($context->constantStringFromString(','));
        $mode = $i64->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false);

        return $context->builder->call(
            $context->lookupFunction('__compiler_number_format'),
            $number,
            $decimals,
            $decSep,
            $thouSep,
            $mode
        );
    }

    private static function rejectNullNum(Context $context, JITVariable $arg): void
    {
        // Only declare(strict_types=1) rejects null; forward profile soft-nulls (#21429).
        if (!$context->callerStrictTypes) {
            return;
        }
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::emitNullNumTypeErrorAndAbort($context);

            return;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'number_format_num_null_ok');
        $failBlock = BasicBlockHelper::append($context, 'number_format_num_null_fail');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            ),
            $failBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($failBlock);
        self::emitNullNumTypeErrorAndAbort($context);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitNullNumTypeErrorAndAbort(Context $context): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, 'number_format(): Argument #1 ($num) must be of type float, null given');
        $context->builder->call($context->lookupFunction('abort'));
    }

}
