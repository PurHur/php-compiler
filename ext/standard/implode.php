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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
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
            self::rejectEnumSeparator($frame->calledArgs[0], $this->getName());
            $glue = VmString::coerceOperand($frame->calledArgs[0]);
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
            self::rejectEnumHaystackElement($value);
            $parts[] = $value->resolveIndirect()->toString();
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
            $glue = JitStringBuiltinArg::lower(
                $context,
                $args[0],
                $this->getName(),
                0,
                'separator',
                'array|string'
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
     * php-src php_implode: array elements must convert to string (#5581, ext/standard/string.c).
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
}
