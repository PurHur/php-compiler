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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * gettype() — Zend type labels (ext/standard/basic_functions.c parity, #3618).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(gettype)
 */
final class gettype extends Internal
{
    private const VM_NAMES = [
        Variable::TYPE_NULL => 'NULL',
        Variable::TYPE_INTEGER => 'integer',
        Variable::TYPE_FLOAT => 'double',
        Variable::TYPE_BOOLEAN => 'boolean',
        Variable::TYPE_STRING => 'string',
        Variable::TYPE_ARRAY => 'array',
    ];

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#21964).
        $this->requireExactArgCount($frame, 'gettype', 1);
        $v = $frame->calledArgs[0]->resolveIndirect();
        TypedPropertyCheck::assertReadable($v);
        if (null === $frame->returnVar) {
            return;
        }
        if ($v->isVmResource()) {
            $frame->returnVar->string(
                ResourceSupport::isClosedVmResource($v) ? 'resource (closed)' : 'resource'
            );

            return;
        }
        if (Variable::TYPE_OBJECT === $v->type || Variable::TYPE_ENUM_CASE === $v->type) {
            $frame->returnVar->string('object');

            return;
        }
        if (!isset(self::VM_NAMES[$v->type])) {
            throw new \LogicException('gettype() does not support this value type in this compiler build');
        }
        $frame->returnVar->string(self::VM_NAMES[$v->type]);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'gettype', 1)) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            TypedPropertyUninitGuard::emitBeforeRead($context, $args[0]);
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY
            || JITVariable::TYPE_HASHTABLE === $args[0]->type) {
            if (0 !== ($args[0]->type & JITVariable::IS_NATIVE_ARRAY)) {
                return $context->builder->load($context->constantStringFromString('array'));
            }
            $isCtx = JitStreamContextRepresentation::isRepresentationArg($context, $args[0]);

            return $context->builder->select(
                $isCtx,
                $context->builder->load($context->constantStringFromString('resource')),
                $context->builder->load($context->constantStringFromString('array'))
            );
        }
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            return $context->builder->load($context->constantStringFromString('object'));
        }
        if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type) {
            return self::jitGettypeLong($context, $args[0]);
        }
        if (JITVariable::TYPE_STRING === $args[0]->type) {
            $this->jitString($context, $args[0], 'gettype() argument #1');
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->builder->load($context->constantStringFromString('double'));
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->builder->load($context->constantStringFromString('boolean'));
            case JITVariable::TYPE_STRING:
                return $context->builder->load($context->constantStringFromString('string'));
            case JITVariable::TYPE_NULL:
                return $context->builder->load($context->constantStringFromString('NULL'));
            case JITVariable::TYPE_VALUE:
                return JitGettype::boxed($context, $args[0]);
            default:
                throw new \LogicException('gettype() does not support this value type in this compiler build');
        }
    }

    private static function jitGettypeLong(Context $context, JITVariable $arg): Value
    {
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $arg, 'gettype() argument #1'),
            $context->getTypeFromString('int64')
        );

        return JitGettype::labelForHandle($context, $handle);
    }
}
