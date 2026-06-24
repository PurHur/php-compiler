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
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
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
 */
final class implode extends Internal
{
    public function __construct(string $name = 'implode')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($this->getName().'() requires one or two arguments in this compiler build');
        }
        if (1 === $argc) {
            $glue = '';
            $ht = VmArray::requireArrayParam(
                $frame->calledArgs[0],
                $this->getName(),
                1,
                'array',
                'array'
            );
        } else {
            self::rejectNullSeparator($frame, $frame->calledArgs[0], $this->getName());
            self::rejectEnumSeparator($frame->calledArgs[0], $this->getName());
            self::rejectArraySeparator($frame->calledArgs[0], $this->getName());
            $glue = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[0],
                $this->getName(),
                0,
                'separator'
            );
            $ht = VmArray::requireArrayParam(
                $frame->calledArgs[1],
                $this->getName(),
                2,
                'array',
                '?array'
            );
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
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($this->getName().'() requires one or two arguments in this compiler build');
        }
        if (1 === $argc) {
            $i64 = $context->getTypeFromString('int64');
            $glue = $context->builder->call(
                $context->lookupFunction('__string__alloc'),
                $i64->constInt(0, false)
            );
            $haystack = $this->loadHaystack($context, $args[0], false);
        } else {
            if (0 !== ($args[0]->type & JITVariable::IS_NATIVE_ARRAY)
                || JITVariable::TYPE_HASHTABLE === $args[0]->type
            ) {
                self::emitSeparatorArrayTypeErrorAndAbort($context, $this->getName());

                return $context->getTypeFromString('__string__*')->constNull();
            }
            self::rejectNullSeparatorJit($context, $args[0], $this->getName());
            $glue = JitStringBuiltinArg::lower(
                $context,
                $args[0],
                $this->getName(),
                0,
                'separator',
                'array|string',
                'string'
            );
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
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::pointer($context, $arg->value)
            );
        }

        return $context->getTypeFromString('__hashtable__*')->constNull();
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

    /** php-src Z_PARAM_STR on implode() separator — null TypeError only under strict_types (#11013, ext/standard/string.c). */
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

    /** php-src php_implode — two-arg form separator must not be array (#4160, ext/standard/string.c). */
    private static function rejectArraySeparator(Variable $var, string $function): void
    {
        if (Variable::TYPE_ARRAY === $var->resolveIndirect()->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($separator) must be of type string, array given',
                $function
            ));
        }
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
        $okBlock = BasicBlockHelper::append($context, 'implode_sep_null_ok');
        $failBlock = BasicBlockHelper::append($context, 'implode_sep_null_fail');
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
        self::emitNullSeparatorTypeErrorAndAbort($context, $function);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitNullSeparatorTypeErrorAndAbort(Context $context, string $function): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, sprintf(
            '%s(): Argument #1 ($separator) must be of type array|string, null given',
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
