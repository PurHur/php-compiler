<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitResourceIdString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\MatchUnhandledSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Match scalar suffix for UnhandledMatchError (#23664).
 *
 * php-src: Zend/zend_smart_str.c — smart_str_append_scalar
 * SSOT: {@see MatchUnhandledSupport::formatCaseSuffix} (scalar arms only)
 */
final class phpc_match_unhandled_format_scalar extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_match_unhandled_format_scalar');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_match_unhandled_format_scalar() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        // Aggregates should not reach this helper — overlay routes them to "of type …".
        if (Variable::TYPE_ARRAY === $var->type
            || Variable::TYPE_OBJECT === $var->type
            || Variable::TYPE_ENUM_CASE === $var->type
        ) {
            $frame->returnVar->string(MatchUnhandledSupport::formatCaseSuffix($var));

            return;
        }
        $frame->returnVar->string(MatchUnhandledSupport::formatCaseSuffix($var));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_match_unhandled_format_scalar() requires exactly one argument');
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NULL:
                return $context->builder->load($context->constantStringFromString('NULL'));
            case JITVariable::TYPE_NATIVE_BOOL:
                return self::boolLabel($context, $context->helper->loadValue($args[0]));
            case JITVariable::TYPE_NATIVE_LONG:
                return JitResourceIdString::formatNativeLong(
                    $context,
                    $context->helper->loadValue($args[0])
                );
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return \PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime::format(
                    $context,
                    $context->helper->loadValue($args[0])
                );
            case JITVariable::TYPE_STRING:
                return self::formatStringPtr($context, $context->helper->loadValue($args[0]));
            case JITVariable::TYPE_VALUE:
                return self::formatBoxed($context, $args[0]);
            default:
                throw new \LogicException(
                    'phpc_match_unhandled_format_scalar() unsupported type in this compiler build'
                );
        }
    }

    private static function formatBoxed(Context $context, JITVariable $arg): Value
    {
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');

        $nullBlock = BasicBlockHelper::append($context, 'umatch_sc_null');
        $boolBlock = BasicBlockHelper::append($context, 'umatch_sc_bool');
        $longBlock = BasicBlockHelper::append($context, 'umatch_sc_long');
        $doubleBlock = BasicBlockHelper::append($context, 'umatch_sc_double');
        $stringBlock = BasicBlockHelper::append($context, 'umatch_sc_string');
        $fallbackBlock = BasicBlockHelper::append($context, 'umatch_sc_fallback');
        $doneBlock = BasicBlockHelper::append($context, 'umatch_sc_done');

        $afterNull = BasicBlockHelper::append($context, 'umatch_sc_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NULL, false)),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        $nullMsg = $context->builder->load($context->constantStringFromString('NULL'));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterBool = BasicBlockHelper::append($context, 'umatch_sc_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_BOOLEAN, false)),
            $boolBlock,
            $afterBool
        );
        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $loaded);
        $boolMsg = self::boolLabel(
            $context,
            $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false))
        );
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterLong = BasicBlockHelper::append($context, 'umatch_sc_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_INTEGER, false)),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $longMsg = JitResourceIdString::formatNativeLong(
            $context,
            $context->builder->call($context->lookupFunction('__value__readLong'), $loaded)
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterDouble = BasicBlockHelper::append($context, 'umatch_sc_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_FLOAT, false)),
            $doubleBlock,
            $afterDouble
        );
        $context->builder->positionAtEnd($doubleBlock);
        $doubleMsg = \PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime::format(
            $context,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $loaded)
        );
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $afterString = BasicBlockHelper::append($context, 'umatch_sc_after_string');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_STRING, false)),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $raw = $context->builder->call($context->lookupFunction('__value__readString'), $loaded);
        $stringMsg = self::formatStringPtr($context, $raw);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $arrayBlock = BasicBlockHelper::append($context, 'umatch_sc_array');
        $afterArray = BasicBlockHelper::append($context, 'umatch_sc_after_array');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_ARRAY, false)),
            $arrayBlock,
            $afterArray
        );
        $context->builder->positionAtEnd($arrayBlock);
        $arrayMsg = $context->builder->load($context->constantStringFromString('of type array'));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterArray);
        $objectBlock = BasicBlockHelper::append($context, 'umatch_sc_object');
        $afterObject = BasicBlockHelper::append($context, 'umatch_sc_after_object');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $isEnum = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf(
            $context->builder->or($isObject, $isEnum),
            $objectBlock,
            $afterObject
        );
        $context->builder->positionAtEnd($objectBlock);
        // Enums: Enum::Case; non-enum objects: of type Class (#29248 / smart_str_append_zval).
        $objectMsg = \PHPCompiler\JIT\MatchUnhandledJitHelper::formatObjectOrEnumCaseSuffix(
            $context,
            $arg
        );
        $objectEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterObject);
        $context->builder->branch($fallbackBlock);
        $context->builder->positionAtEnd($fallbackBlock);
        // Should not reach for overlay-routed scalars; keep a safe label.
        $fallbackMsg = $context->builder->load($context->constantStringFromString('NULL'));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($context->getTypeFromString('__string__*'));
        $phi->addIncoming($nullMsg, $nullBlock);
        $phi->addIncoming($boolMsg, $boolEnd);
        $phi->addIncoming($longMsg, $longEnd);
        $phi->addIncoming($doubleMsg, $doubleEnd);
        $phi->addIncoming($stringMsg, $stringEnd);
        $phi->addIncoming($arrayMsg, $arrayBlock);
        $phi->addIncoming($objectMsg, $objectEnd);
        $phi->addIncoming($fallbackMsg, $fallbackBlock);

        return $phi;
    }

    private static function boolLabel(Context $context, Value $bool): Value
    {
        $trueBlock = BasicBlockHelper::append($context, 'umatch_sc_true');
        $falseBlock = BasicBlockHelper::append($context, 'umatch_sc_false');
        $endBlock = BasicBlockHelper::append($context, 'umatch_sc_bool_end');
        $context->builder->branchIf($bool, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        $t = $context->builder->load($context->constantStringFromString('true'));
        $context->builder->branch($endBlock);
        $context->builder->positionAtEnd($falseBlock);
        $f = $context->builder->load($context->constantStringFromString('false'));
        $context->builder->branch($endBlock);
        $context->builder->positionAtEnd($endBlock);
        $phi = $context->builder->phi($t->typeOf());
        $phi->addIncoming($t, $trueBlock);
        $phi->addIncoming($f, $falseBlock);

        return $phi;
    }

    /**
     * Quote/truncate (VM does full escape; JIT covers alphanumeric + max_len trunc).
     */
    private static function formatStringPtr(Context $context, Value $strPtr): Value
    {
        $maxLen = IniJitHelper::getExceptionStringParamMaxLen();
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($strPtr, $map['length']));
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $emptyBlock = BasicBlockHelper::append($context, 'umatch_fmt_empty');
        $nonEmptyBlock = BasicBlockHelper::append($context, 'umatch_fmt_nonempty');
        $doneBlock = BasicBlockHelper::append($context, 'umatch_fmt_done');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $context->builder->branchIf($isEmpty, $emptyBlock, $nonEmptyBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyMsg = $context->builder->load($context->constantStringFromString("''"));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($nonEmptyBlock);
        if (0 === $maxLen) {
            $truncMsg = $context->builder->load($context->constantStringFromString("'...'"));
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($doneBlock);
            $phi = $context->builder->phi($context->getTypeFromString('__string__*'));
            $phi->addIncoming($emptyMsg, $emptyBlock);
            $phi->addIncoming($truncMsg, $nonEmptyBlock);

            return $phi;
        }

        // max_len > 0: quote the full string (truncate/escape on VM path; short subjects OK).
        // JitStringConcat::concat ends in a fresh continue block — PHI predecessors must be the
        // *actual* terminator blocks, not $nonEmptyBlock (#24388 / LLVM "PHI node entries do not
        // match predecessors" + "Instruction does not dominate all uses").
        $open = $context->builder->load($context->constantStringFromString("'"));
        $close = $context->builder->load($context->constantStringFromString("'"));
        $mid = JitStringConcat::concat($context, $open, $strPtr);
        $full = JitStringConcat::concat($context, $mid, $close);
        $nonEmptyEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($context->getTypeFromString('__string__*'));
        $phi->addIncoming($emptyMsg, $emptyBlock);
        $phi->addIncoming($full, $nonEmptyEnd);

        return $phi;
    }
}
