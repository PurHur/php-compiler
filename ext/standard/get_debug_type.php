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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * get_debug_type() — PHP 8.0 precise type names (ext/standard/type.c parity).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/type.c PHP_FUNCTION(get_debug_type)
 */
final class get_debug_type extends Internal
{
    private const VM_NAMES = [
        Variable::TYPE_NULL => 'null',
        Variable::TYPE_INTEGER => 'int',
        Variable::TYPE_FLOAT => 'float',
        Variable::TYPE_BOOLEAN => 'bool',
        Variable::TYPE_STRING => 'string',
        Variable::TYPE_ARRAY => 'array',
    ];

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/type.c — ArgumentCountError (#28317).
        $this->requireExactArgCount($frame, 'get_debug_type', 1);
        $v = $frame->calledArgs[0]->resolveIndirect();
        TypedPropertyCheck::assertReadable($v);
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ENUM_CASE === $v->type) {
            $frame->returnVar->string($v->toEnumCase()->enumClass->name);

            return;
        }
        $resourceDebug = \PHPCompiler\VM\ResourceSupport::debugTypeName($v);
        if (null !== $resourceDebug) {
            $frame->returnVar->string($resourceDebug);

            return;
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            $frame->returnVar->string(
                VmObjectDebugType::fromClassName($v->toObject()->class->name)
            );

            return;
        }
        if (!isset(self::VM_NAMES[$v->type])) {
            throw new \LogicException('get_debug_type() does not support this value type in this compiler build');
        }
        $frame->returnVar->string(self::VM_NAMES[$v->type]);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT try/catch) — peer htmlspecialchars #28285 / #28317.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('get_debug_type() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
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
                $context->builder->load($context->constantStringFromString('resource (stream-context)')),
                $context->builder->load($context->constantStringFromString('array'))
            );
        }
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            return ReflectionBuiltinHelper::getDebugTypeClassName($context, $args[0]);
        }
        if (JITVariable::TYPE_STRING === $args[0]->type) {
            $this->jitString($context, $args[0], 'get_debug_type() argument #1');
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $context->builder->load($context->constantStringFromString('int'));
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->builder->load($context->constantStringFromString('float'));
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->builder->load($context->constantStringFromString('bool'));
            case JITVariable::TYPE_STRING:
                return $context->builder->load($context->constantStringFromString('string'));
            case JITVariable::TYPE_NULL:
                return $context->builder->load($context->constantStringFromString('null'));
            case JITVariable::TYPE_VALUE:
                $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $args[0]);
                if (null !== $enumLabel) {
                    return $context->builder->load($context->constantStringFromString($enumLabel));
                }

                return self::jitGetDebugTypeBoxed($context, $args[0]);
            default:
                throw new \LogicException('get_debug_type() does not support this value type in this compiler build');
        }
    }

    /**
     * Boxed TYPE_VALUE path — must GEP/read via {@see JitValueBox::valuePtrFromVariable}
     * (loadValue returns a struct, not `__value__*`; thin AOT compile segfaulted, #26885).
     * Shape mirrors {@see JitGettype::boxed}; HT/object probes are BB-guarded so
     * select operands do not null-deref (#26885 runtime).
     */
    private static function jitGetDebugTypeBoxed(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
        $valMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $valMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        // Mask IS_REFCOUNTED — AOT stores JIT tags (TYPE_*|0x80) (#26854 / #26885).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $enumCaseTy = $i8->constInt(Variable::TYPE_ENUM_CASE & 0x7f, false);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $kind, $enumCaseTy);
        $enumBb = BasicBlockHelper::append($context, 'get_debug_type_enum_case');
        $scalarBb = BasicBlockHelper::append($context, 'get_debug_type_scalar');
        $doneBb = BasicBlockHelper::append($context, 'get_debug_type_boxed_done');
        $context->builder->branchIf($isEnumCase, $enumBb, $scalarBb);

        $context->builder->positionAtEnd($enumBb);
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null !== $enumMap && isset($enumMap['class_id'])) {
            $classId = $context->builder->load(
                $context->builder->structGep($valuePtr, $enumMap['class_id'])
            );
            $enumName = ReflectionBuiltinHelper::classNameStringFromClassId($context, $classId);
        } else {
            $enumName = $context->builder->load($context->constantStringFromString('unknown'));
        }
        $enumEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($scalarBb);
        $result = $context->builder->load($context->constantStringFromString('unknown'));
        foreach ([
            JITVariable::TYPE_NULL => 'null',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_STRING => 'string',
        ] as $jitType => $name) {
            $expected = $i8->constInt($jitType & 0x7f, false);
            $isType = $context->builder->icmp(Builder::INT_EQ, $kind, $expected);
            $candidate = $context->builder->load($context->constantStringFromString($name));
            $result = $context->builder->select($isType, $candidate, $result);
        }

        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_HASHTABLE, false)
        );
        $htProbeBb = BasicBlockHelper::append($context, 'get_debug_type_ht_probe');
        $afterHtBb = BasicBlockHelper::append($context, 'get_debug_type_after_ht');
        $context->builder->branchIf($isHt, $htProbeBb, $afterHtBb);

        $context->builder->positionAtEnd($htProbeBb);
        $htLabel = $context->builder->select(
            JitStreamContextRepresentation::isRepresentation(
                $context,
                $context->builder->call(
                    $context->lookupFunction('__value__readHashtable'),
                    $valuePtr
                )
            ),
            $context->builder->load($context->constantStringFromString('resource (stream-context)')),
            $context->builder->load($context->constantStringFromString('array'))
        );
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterHtBb);

        $context->builder->positionAtEnd($afterHtBb);
        $strPtr = $context->getTypeFromString('__string__*');
        $afterHtPhi = $context->builder->phi($strPtr, 'get_debug_type_ht_phi');
        $afterHtPhi->addIncoming($result, $scalarBb);
        $afterHtPhi->addIncoming($htLabel, $htEnd);
        $result = $afterHtPhi;

        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $objProbeBb = BasicBlockHelper::append($context, 'get_debug_type_obj_probe');
        $afterObjBb = BasicBlockHelper::append($context, 'get_debug_type_after_obj');
        $context->builder->branchIf($isObject, $objProbeBb, $afterObjBb);

        $context->builder->positionAtEnd($objProbeBb);
        $objectLabel = ReflectionBuiltinHelper::getDebugTypeClassName($context, $arg);
        $objEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterObjBb);

        $context->builder->positionAtEnd($afterObjBb);
        $afterObjPhi = $context->builder->phi($strPtr, 'get_debug_type_obj_phi');
        $afterObjPhi->addIncoming($result, $afterHtBb);
        $afterObjPhi->addIncoming($objectLabel, $objEnd);
        $result = $afterObjPhi;
        $scalarEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($enumName, $enumEnd);
        $phi->addIncoming($result, $scalarEnd);

        return $phi;
    }
}
