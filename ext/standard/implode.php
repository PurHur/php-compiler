<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * implode() with glue and array of scalar values (subset of PHP; JIT/AOT via JitImplode).
 *
 * $separator soft-null DEP+coerce on PROFILE=8.4 (#21210, reverts #19894; php-src string.c).
 */
final class implode extends Internal
{
    public function __construct(string $name = 'implode')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#21964).
        $this->requireArgCountRange($frame, $this->getName(), 1, 2);
        $argc = \count($frame->calledArgs);
        if (1 === $argc) {
            self::rejectNullSeparator($frame, $frame->calledArgs[0], $this->getName());
            $glue = '';
            $ht = VmArray::requireArrayParam(
                $frame->calledArgs[0],
                $this->getName(),
                1,
                'array',
                'array'
            );
        } else {
            // php-src PHP_FUNCTION(implode): Z_PARAM_ARRAY_HT_OR_STR + Z_PARAM_ARRAY_HT_OR_NULL;
            // when pieces == NULL, string/coerced first arg TypeErrors as Argument #1 ($array) (#19566).
            $first = $frame->calledArgs[0]->resolveIndirect();
            $second = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $first->type) {
                if (Variable::TYPE_NULL === $second->type) {
                    $glue = '';
                    $ht = $first->toArray();
                } else {
                    self::rejectArrayFirstTwoArgForm($frame, $this->getName());

                    return;
                }
            } elseif (Variable::TYPE_NULL === $second->type) {
                self::rejectNullSeparator($frame, $frame->calledArgs[0], $this->getName());
                self::rejectEnumSeparator($frame->calledArgs[0], $this->getName());
                // Soft-null separator then Arg #1 ($array) string given (#19566 / #21210).
                self::coerceSeparatorSoftNull($frame->calledArgs[0], $this->getName());
                throw new \TypeError(sprintf(
                    '%s(): Argument #1 ($array) must be of type array, string given',
                    $this->getName()
                ));
            } else {
                self::rejectNullSeparator($frame, $frame->calledArgs[0], $this->getName());
                self::rejectEnumSeparator($frame->calledArgs[0], $this->getName());
                $glue = self::coerceSeparatorSoftNull($frame->calledArgs[0], $this->getName());
                $ht = VmArray::requireArrayParam(
                    $frame->calledArgs[1],
                    $this->getName(),
                    2,
                    'array',
                    '?array'
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $parts = [];
        foreach ($ht->iterate(true) as $value) {
            $parts[] = self::coerceHaystackElement($frame, $value);
        }
        $frame->returnVar->string(VmString::implode($glue, $parts));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, $this->getName(), 1, 2)) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        $argc = \count($args);
        if (1 === $argc) {
            self::rejectNullSeparatorJit($context, $args[0], $this->getName());
            $i64 = $context->getTypeFromString('int64');
            $glue = $context->builder->call(
                $context->lookupFunction('__string__alloc'),
                $i64->constInt(0, false)
            );
            $haystack = $this->loadHaystack($context, $args[0], false);
        } else {
            // php-src pieces == NULL (#19566): array-first empty glue, or Arg #1 ($array) string given.
            if (self::jitArgIsDefinitelyNull($args[1])) {
                if (self::jitArgIsDefinitelyArray($args[0])) {
                    $i64 = $context->getTypeFromString('int64');
                    $glue = $context->builder->call(
                        $context->lookupFunction('__string__alloc'),
                        $i64->constInt(0, false)
                    );
                    $haystack = $this->loadHaystack($context, $args[0], false);

                    return JitImplode::implode($context, $glue, $haystack);
                }
                if (JITVariable::TYPE_VALUE === $args[0]->type) {
                    // Boxed first + null pieces: array → empty glue; else Arg #1 ($array).
                    return $this->jitTwoArgNullPiecesBoxedFirst($context, $args[0], $this->getName());
                }
                self::rejectNullSeparatorJit($context, $args[0], $this->getName());
                self::lowerSeparatorSoftNull($context, $args[0], $this->getName());
                self::emitPiecesNullStringFirstTypeErrorAndAbort($context, $this->getName());

                return $context->getTypeFromString('__string__*')->constNull();
            }
            if (self::jitArgIsDefinitelyArray($args[0])) {
                if (JITVariable::TYPE_VALUE === $args[1]->type) {
                    return $this->jitArrayFirstWithBoxedSecond($context, $args[0], $args[1], $this->getName());
                }
                self::jitRejectArrayFirstTwoArgForm($context, $args[1], $this->getName());

                return $context->getTypeFromString('__string__*')->constNull();
            }
            if (JITVariable::TYPE_VALUE === $args[0]->type) {
                return $this->jitTwoArgBoxedFirstDispatch($context, $args[0], $args[1], $this->getName());
            }
            self::rejectNullSeparatorJit($context, $args[0], $this->getName());
            $glue = self::lowerSeparatorSoftNull($context, $args[0], $this->getName());
            self::jitRejectNullPiecesModernForm($context, $args[1], $this->getName());
            $haystack = $this->loadHaystack($context, $args[1], true);
        }

        return JitImplode::implode($context, $glue, $haystack);
    }

    private function loadHaystack(Context $context, JITVariable $arg, bool $glueAndArrayForm): Value
    {
        JitArrayElem::requireArrayParam(
            $context,
            $arg,
            $this->getName(),
            $glueAndArrayForm ? 2 : 1,
            'array',
            $glueAndArrayForm ? '?array' : 'array'
        );

        // Use the shared array-operand loader — KIND_VARIABLE locals are often value-boxed
        // hashtables; helper->loadValue alone passes a garbage __hashtable__* into JitImplode
        // and segfaults in __hashtable__getNumElements (#23974 e23).
        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }

    /**
     * php-src php_implode: array elements via zval_get_string (#5581, #9557, ext/standard/string.c).
     */
    private static function coerceHaystackElement(Frame $frame, Variable $value): string
    {
        self::rejectEnumHaystackElement($value);
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type) {
            $vm = $frame->vmContext?->runtime->vm ?? null;
            if (null === $vm) {
                throw new \Error(
                    'Object of class '.$value->toObject()->class->name.' could not be converted to string'
                );
            }

            return $vm->castObjectToString($value->toObject());
        }

        return VmString::coerceOperand($value);
    }

    /**
     * php-src php_implode: enum case elements must Error, not stringify (#5581, ext/standard/string.c).
     */
    private static function rejectEnumHaystackElement(Variable $value): void
    {
        $value = $value->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($value)) {
            return;
        }
        throw new \Error(
            'Object of class '.EnumCaseSupport::typeNameForVariable($value).' could not be converted to string'
        );
    }

    /**
     * php-src Z_PARAM_STR on implode() separator — enum cases must TypeError (#7114, ext/standard/string.c).
     */
    private static function rejectEnumSeparator(Variable $var, string $function): void
    {
        $var = $var->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        throw new \TypeError(sprintf(
            '%s(): Argument #1 ($separator) must be of type array|string, %s given',
            $function,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    /**
     * php-src Z_PARAM_ARRAY_HT_OR_STR — null TypeError only under declare(strict_types=1)
     * (#11013, #18632). PROFILE=8.4 soft-null DEP+coerce (#21210, reverts #19894).
     */
    private static function rejectNullSeparator(Frame $frame, Variable $var, string $function): void
    {
        if (!InternalStrictArg::isCallerStrict($frame)) {
            return;
        }
        if (Variable::TYPE_NULL === $var->resolveIndirect()->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($separator) must be of type array|string, null given',
                $function
            ));
        }
    }

    /**
     * Soft-null separator — Zend 8.4 deprecate+coerce (#21210; php-src string.c implode).
     */
    private static function coerceSeparatorSoftNull(Variable $var, string $function): string
    {
        return VmString::coerceStringBuiltinArg(
            $var,
            $function,
            0,
            'separator',
            'array|string',
            false
        );
    }

    /**
     * JIT soft-null separator — same as {@see coerceSeparatorSoftNull} (#21210).
     */
    private static function lowerSeparatorSoftNull(Context $context, JITVariable $arg, string $function): Value
    {
        return JitStringBuiltinArg::lower(
            $context,
            $arg,
            $function,
            0,
            'separator',
            'array|string',
            'string',
            false,
            false
        );
    }

    /**
     * php-src Z_PARAM_ARRAY_HT_OR_STR + Z_PARAM_ARRAY_HT_OR_NULL — array-first two-arg form (#16401).
     *
     * When argument #1 is an array, invalid glue is reported on argument #2 ($array).
     */
    private static function rejectArrayFirstTwoArgForm(Frame $frame, string $function): void
    {
        $second = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY === $second->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($separator) must be of type string, array given',
                $function
            ));
        }
        VmArray::requireArrayParam(
            $frame->calledArgs[1],
            $function,
            2,
            'array',
            '?array'
        );
    }

    private static function jitArgIsDefinitelyArray(JITVariable $arg): bool
    {
        return JITVariable::TYPE_HASHTABLE === $arg->type
            || 0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY);
    }

    private static function jitArgIsDefinitelyNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    /** Boxed first + null pieces (#19566). */
    private function jitTwoArgNullPiecesBoxedFirst(
        Context $context,
        JITVariable $firstArg,
        string $function
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $firstArg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayBlock = BasicBlockHelper::append($context, 'implode_null_pieces_array');
        $stringBlock = BasicBlockHelper::append($context, 'implode_null_pieces_string');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_ARRAY, false)
            ),
            $arrayBlock,
            $stringBlock
        );
        $context->builder->positionAtEnd($stringBlock);
        self::rejectNullSeparatorJit($context, $firstArg, $function);
        self::lowerSeparatorSoftNull($context, $firstArg, $function);
        self::emitPiecesNullStringFirstTypeErrorAndAbort($context, $function);
        $context->builder->positionAtEnd($arrayBlock);
        $i64 = $context->getTypeFromString('int64');
        $glue = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $i64->constInt(0, false)
        );
        $haystack = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::pointer($context, $firstArg->value)
        );

        return JitImplode::implode($context, $glue, $haystack);
    }

    /**
     * Array-first + boxed second: null → empty glue; else same as jitRejectArrayFirstTwoArgForm (#19566).
     */
    private function jitArrayFirstWithBoxedSecond(
        Context $context,
        JITVariable $firstArg,
        JITVariable $secondArg,
        string $function
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $secondArg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $nullBlock = BasicBlockHelper::append($context, 'implode_array_first_null_pieces');
        $badBlock = BasicBlockHelper::append($context, 'implode_array_first_bad_pieces');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NULL, false)
            ),
            $nullBlock,
            $badBlock
        );
        $context->builder->positionAtEnd($badBlock);
        $arraySepBlock = BasicBlockHelper::append($context, 'implode_array_first_sep_is_array');
        $otherBlock = BasicBlockHelper::append($context, 'implode_array_first_other');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_ARRAY, false)
            ),
            $arraySepBlock,
            $otherBlock
        );
        $context->builder->positionAtEnd($arraySepBlock);
        self::emitSeparatorArrayTypeErrorAndAbort($context, $function);
        $context->builder->positionAtEnd($otherBlock);
        JitArrayElem::requireArrayParam($context, $secondArg, $function, 2, 'array', '?array');
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($nullBlock);
        $i64 = $context->getTypeFromString('int64');
        $glue = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $i64->constInt(0, false)
        );
        $haystack = $this->loadHaystack($context, $firstArg, false);

        return JitImplode::implode($context, $glue, $haystack);
    }

    /**
     * Modern form: null pieces → Arg #1 ($array), string given (#19566).
     */
    private static function jitRejectNullPiecesModernForm(
        Context $context,
        JITVariable $secondArg,
        string $function
    ): void {
        if (self::jitArgIsDefinitelyNull($secondArg)) {
            self::emitPiecesNullStringFirstTypeErrorAndAbort($context, $function);

            return;
        }
        if (JITVariable::TYPE_VALUE !== $secondArg->type) {
            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $secondArg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'implode_pieces_null_ok');
        $failBlock = BasicBlockHelper::append($context, 'implode_pieces_null_fail');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NULL, false)
            ),
            $failBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($failBlock);
        self::emitPiecesNullStringFirstTypeErrorAndAbort($context, $function);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function jitRejectArrayFirstTwoArgForm(
        Context $context,
        JITVariable $secondArg,
        string $function
    ): void {
        if (self::jitArgIsDefinitelyArray($secondArg)) {
            self::emitSeparatorArrayTypeErrorAndAbort($context, $function);

            return;
        }
        JitArrayElem::requireArrayParam($context, $secondArg, $function, 2, 'array', '?array');
    }

    private function jitTwoArgBoxedFirstDispatch(
        Context $context,
        JITVariable $firstArg,
        JITVariable $secondArg,
        string $function
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $firstArg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayBlock = BasicBlockHelper::append($context, 'implode_two_arg_array_first');
        $modernBlock = BasicBlockHelper::append($context, 'implode_two_arg_modern');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_ARRAY, false)
            ),
            $arrayBlock,
            $modernBlock
        );
        $context->builder->positionAtEnd($arrayBlock);
        self::jitRejectArrayFirstTwoArgForm($context, $secondArg, $function);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($modernBlock);
        // Soft-null boxed separator on PROFILE=8.4 (#21210, reverts #19894).
        $glue = self::lowerSeparatorSoftNull($context, $firstArg, $function);
        self::jitRejectNullPiecesModernForm($context, $secondArg, $function);
        $haystack = $this->loadHaystack($context, $secondArg, true);

        return JitImplode::implode($context, $glue, $haystack);
    }

    private static function rejectNullSeparatorJit(Context $context, JITVariable $arg, string $function): void
    {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::emitNullSeparatorTypeErrorAndAbort($context, $function);

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
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $okBlock = BasicBlockHelper::append($context, 'implode_sep_null_ok');
        $failBlock = BasicBlockHelper::append($context, 'implode_sep_null_fail');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(Variable::TYPE_NULL, false)
            ),
            $failBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($failBlock);
        self::emitNullSeparatorTypeErrorAndAbort($context, $function);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitNullSeparatorTypeErrorAndAbort(Context $context, string $function): void
    {
        // ExceptionBridge — TypeErrorRaise+abort SIGABRTs on AOT without PHP fatal (#19894, #19276).
        ExceptionBridge::emitTypeErrorAndAbort($context, sprintf(
            '%s(): Argument #1 ($separator) must be of type array|string, null given',
            $function
        ));
    }

    /** php-src pieces==NULL + string first — Argument #1 ($array), string given (#19566). */
    private static function emitPiecesNullStringFirstTypeErrorAndAbort(Context $context, string $function): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, sprintf(
            '%s(): Argument #1 ($array) must be of type array, string given',
            $function
        ));
        $context->builder->call($context->lookupFunction('abort'));
    }

    /** AOT standalone: libc abort like JitArrayElem::emitErrorAndAbort (#4160). */
    private static function emitSeparatorArrayTypeErrorAndAbort(Context $context, string $function): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, sprintf(
            '%s(): Argument #1 ($separator) must be of type string, array given',
            $function
        ));
        $context->builder->call($context->lookupFunction('abort'));
    }
}
