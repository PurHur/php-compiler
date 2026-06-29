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
use PHPCompiler\JIT\Builtin\ArrayKeyExistsRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_key_exists() / key_exists() for arrays with int, float, or string keys (php-src subset).
 */
final class array_key_exists extends Internal
{
    public function __construct(string $name = 'array_key_exists')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $this->requireExactArgCount($frame, $fn, 2);
        $key = $frame->calledArgs[0]->resolveIndirect();
        $array = VmArray::requireArrayParam(
            $frame->calledArgs[1],
            $fn,
            2,
            'array'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($array->hasKey($key));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (!$this->requireExactJitArgCount($context, $args, $fn, 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $key = $args[0];
        $array = $args[1];
        if (JITVariable::TYPE_HASHTABLE !== $array->type
            && !($array->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            if (JITVariable::TYPE_VALUE === $array->type) {
                JitArrayElem::requireArrayParam($context, $array, $fn, 2, 'array');

                return ArrayKeyExistsRuntime::keyExists($context, $key, $array, $fn);
            }
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::emitRaise(
                $context,
                \sprintf(
                    '%s(): Argument #2 ($array) must be of type array, %s given',
                    $fn,
                    self::jitArgTypeLabel($array)
                )
            );
            $context->builder->call($context->lookupFunction('abort'));

            return $context->constantFromInteger(0, 'int1');
        }

        return ArrayKeyExistsRuntime::keyExists($context, $key, $array, $fn);
    }

    /** Public bridge for {@see ArrayKeyExistsRuntime} standalone LLVM string keys (#13735). */
    public static function jitKeyString(Context $context, JITVariable $key, string $label): Value
    {
        return (new self('array_key_exists'))->jitString($context, $key, $label);
    }

    private static function jitArgTypeLabel(JITVariable $arg): string
    {
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            case JITVariable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
