<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSubstrCompare;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * substr_compare() — compare haystack slice to needle (subset of PHP; issue #2400).
 * JIT lowers via {@see StringSubstrCompare} + {@see SubstrCompareJitHelper} (VmString parity; no phpc_substr_compare.c).
 */
final class substr_compare extends Internal
{
    public function __construct()
    {
        parent::__construct('substr_compare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException('substr_compare() accepts three to five arguments in this compiler build');
        }
        $haystack = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'substr_compare', 0, 'haystack');
        $needle = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'substr_compare', 1, 'needle');
        $offsetInt = self::requireIntArg($frame->calledArgs[2], 'substr_compare', 3, 'offset');
        $length = null;
        if ($argc >= 4) {
            $lengthArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $lengthArg->type) {
                $length = self::requireIntArg($frame->calledArgs[3], 'substr_compare', 4, 'length');
            }
        }
        $caseInsensitive = false;
        if (5 === $argc) {
            $ci = $frame->calledArgs[4]->resolveIndirect();
            $caseInsensitive = $ci->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::substr_compare(
            $haystack,
            $needle,
            $offsetInt,
            $length,
            $caseInsensitive
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        StringSubstrCompare::ensureLinked($context);
        $argc = \count($args);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException('substr_compare() accepts three to five arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $lengthVal = $i64->constInt(-1, false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_VALUE === $args[3]->type && $args[3]->isNullConstant) {
                $lengthVal = $i64->constInt(-1, false);
            } else {
                $lengthVal = self::lowerStrictIntArg($context, $args[3], 'substr_compare', 4, 'length');
            }
        }
        $ci = $i32->constInt(0, false);
        if (5 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[4]->type) {
                throw new \LogicException('substr_compare() case_insensitive must be a boolean in this compiler build');
            }
            $ci = $context->builder->zExt(
                $this->jitBool($context, $args[4], 'substr_compare() case_insensitive'),
                $i32
            );
        }
        $p0 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[0], 'substr_compare', 0, 'haystack'));
        $p1 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[1], 'substr_compare', 1, 'needle'));
        $offset = self::lowerStrictIntArg($context, $args[2], 'substr_compare', 3, 'offset');
        $fn = $context->lookupFunction('substr_compare');
        $raw = $context->builder->call($fn, $p0, $p1, $offset, $lengthVal, $ci);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }

    /**
     * @throws \TypeError
     */
    private static function requireIntArg(Variable $var, string $function, int $argIndex, string $paramName): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $argIndex,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        return $var->toInt();
    }

    private static function lowerStrictIntArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitIntTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedStrictIntArg($context, $arg, $function, $argIndex, $paramName);
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type) {
            self::emitIntTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return $context->helper->loadValue($arg);
    }

    private static function lowerBoxedStrictIntArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayTy = $i8->constInt(Variable::TYPE_ARRAY, false);
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT, false);
        $enumCaseTy = $i8->constInt(Variable::TYPE_ENUM_CASE, false);
        $intTy = $i8->constInt(Variable::TYPE_INTEGER, false);

        $okBlock = BasicBlockHelper::append($context, 'substr_compare_int_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'substr_compare_int_array');
        $rejectBlock = BasicBlockHelper::append($context, 'substr_compare_int_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'substr_compare_int_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

        $context->builder->positionAtEnd($okBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitIntTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($coerceBlock);
        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $intTy);
        $intOkBlock = BasicBlockHelper::append($context, 'substr_compare_int_read');
        $stringErrBlock = BasicBlockHelper::append($context, 'substr_compare_int_string_err');
        $context->builder->branchIf($isInt, $intOkBlock, $stringErrBlock);

        $context->builder->positionAtEnd($stringErrBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'string');

        $context->builder->positionAtEnd($intOkBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
    }

    private static function emitIntTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $argIndex,
                $paramName,
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }
}
