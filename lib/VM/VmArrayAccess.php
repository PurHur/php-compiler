<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPTypes\Type;
use PHPLLVM\Value;

/**
 * SSOT for JIT ArrayAccess $obj[$key] lowering (Zend read_dimension / write_dimension, #3331, #4012, #10246).
 *
 * php-src: Zend/zend_execute.c — ArrayAccess dimension handlers
 * php-src: Zend/zend_interfaces.c — offsetGet/offsetSet/offsetExists/offsetUnset
 *
 * VM runtime: {@see \PHPCompiler\VM\ArrayAccessDimension}
 */
final class VmArrayAccess
{
    private const IFACE_LC = 'arrayaccess';

    public static function containerImplementsArrayAccess(
        Context $context,
        JitVariable $container,
        ?Operand $containerOp
    ): bool {
        $classLc = self::resolveContainerClassLc($container, $containerOp);
        if (null === $classLc || 'object' === $classLc) {
            return false;
        }

        return in_array(
            self::IFACE_LC,
            $context->type->object->allInterfacesForClassLc($classLc),
            true
        );
    }

    public static function tryCompileDimFetch(
        Context $context,
        JitVariable $container,
        JitVariable $dim,
        ?Operand $containerOp,
        bool $forWrite
    ): ?JitVariable {
        if ($container->isArrayAccessWritableOffset) {
            if ($forWrite) {
                $receiver = $container->writableArrayAccessReceiver;
                if (null === $receiver) {
                    throw new \LogicException('ArrayAccess writable offset missing receiver');
                }
                self::emitIndirectModifyNotice(
                    $context,
                    self::resolveContainerClassLc($receiver, $containerOp) ?? 'ArrayAccess'
                );

                return self::discardAssignTarget($context);
            }

            return self::offsetGet($context, $container->writableArrayAccessReceiver, $dim);
        }

        if (!self::canUseArrayAccess($context, $container, $containerOp)) {
            return null;
        }

        if ($forWrite) {
            return self::writableOffset($context, $container, $dim);
        }

        return self::offsetGet($context, $container, $dim);
    }

    public static function tryCompileOffsetIsSet(
        Context $context,
        JitVariable $container,
        JitVariable $dim,
        ?Operand $containerOp
    ): ?Value {
        if (!self::canUseArrayAccess($context, $container, $containerOp)) {
            return null;
        }

        $raw = self::invokeOffsetMethod($context, 'offsetexists', $container, $dim);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $slot,
            JitValueBox::normalizeValuePtr($context, $raw)
        );
        $boxed = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        $boxed->addref();

        return (new \PHPCompiler\ext\standard\boolval())->call($context, $boxed);
    }

    public static function tryCompileOffsetUnset(
        Context $context,
        JitVariable $container,
        JitVariable $dim,
        ?Operand $containerOp
    ): bool {
        if (!self::canUseArrayAccess($context, $container, $containerOp)) {
            return false;
        }
        self::invokeOffsetMethod($context, 'offsetunset', $container, $dim);

        return true;
    }

    public static function isKnownNonArrayAccessObject(
        Context $context,
        JitVariable $container,
        ?Operand $containerOp
    ): bool {
        if (JitVariable::TYPE_OBJECT !== $container->type) {
            return false;
        }
        $classLc = self::resolveContainerClassLc($container, $containerOp);
        if (null === $classLc || 'object' === $classLc) {
            return false;
        }

        return !in_array(
            self::IFACE_LC,
            $context->type->object->allInterfacesForClassLc($classLc),
            true
        );
    }

    public static function emitIllegalOffset(Context $context): void
    {
        $message = 'Illegal offset';
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            self::stringDataPtrFromLiteral($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
    }

    public static function emitIndirectModifyNotice(Context $context, string $className): void
    {
        $message = sprintf(
            'Indirect modification of overloaded element of %s has no effect',
            $className
        );
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_NOTICE, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    public static function assignWritableOffset(Context $context, JitVariable $lvalue, JitVariable $value): void
    {
        if (null === $lvalue->writableArrayAccessReceiver || null === $lvalue->writableArrayAccessKey) {
            throw new \LogicException('ArrayAccess writable offset missing receiver or key');
        }
        self::invokeOffsetMethod(
            $context,
            'offsetset',
            $lvalue->writableArrayAccessReceiver,
            $lvalue->writableArrayAccessKey,
            $value
        );
    }

    private static function discardAssignTarget(Context $context): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $var = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VALUE, $slot);
        $var->addref();

        return $var;
    }

    private static function canUseArrayAccess(
        Context $context,
        JitVariable $container,
        ?Operand $containerOp
    ): bool {
        if (JitVariable::TYPE_OBJECT !== $container->type) {
            return false;
        }
        $classLc = self::resolveContainerClassLc($container, $containerOp);
        if (null !== $classLc && 'object' !== $classLc) {
            return in_array(
                self::IFACE_LC,
                $context->type->object->allInterfacesForClassLc($classLc),
                true
            );
        }

        return self::hasRuntimeArrayAccessCandidates($context);
    }

    private static function hasRuntimeArrayAccessCandidates(Context $context): bool
    {
        return [] !== self::arrayAccessMethodCandidates($context, 'offsetget');
    }

    private static function writableOffset(
        Context $context,
        JitVariable $container,
        JitVariable $dim
    ): JitVariable {
        $slot = JitValueBox::alloc($context);
        $var = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        $var->writableArrayAccessReceiver = $container;
        $var->writableArrayAccessKey = $dim;
        $var->isArrayAccessWritableOffset = true;

        return $var;
    }

    private static function offsetGet(
        Context $context,
        JitVariable $container,
        JitVariable $dim
    ): JitVariable {
        $raw = self::invokeOffsetMethod($context, 'offsetget', $container, $dim);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $slot,
            JitValueBox::normalizeValuePtr($context, $raw)
        );
        $var = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        $var->addref();

        return $var;
    }

    private static function invokeOffsetMethod(
        Context $context,
        string $methodLc,
        JitVariable $receiver,
        JitVariable ...$extraArgs
    ): Value {
        $candidates = self::arrayAccessMethodCandidates($context, $methodLc);
        if ([] === $candidates) {
            throw new \LogicException('No JIT lowering for ArrayAccess::'.$methodLc.'()');
        }
        $call = new RuntimeIndirectInstanceMethodCall($receiver, $methodLc, $candidates);

        return $call->call($context, $receiver, ...$extraArgs);
    }

    /**
     * @return array<int, Call>
     */
    private static function arrayAccessMethodCandidates(Context $context, string $methodLc): array
    {
        $methodLc = strtolower($methodLc);
        $candidates = [];
        foreach ($context->type->object->allClassNamesById() as $classId => $className) {
            $classLc = strtolower(ltrim($className, '\\'));
            if (!in_array(self::IFACE_LC, $context->type->object->allInterfacesForClassLc($classLc), true)) {
                continue;
            }
            $proxyName = $classLc.'::'.$methodLc;
            if (!$context->functionIsRegistered($proxyName)) {
                continue;
            }
            $candidates[$classId] = $context->resolveFunctionProxy($proxyName);
        }

        return $candidates;
    }

    private static function resolveContainerClassLc(
        JitVariable $container,
        ?Operand $containerOp
    ): ?string {
        if (null !== $containerOp && null !== $containerOp->type && Type::TYPE_OBJECT === $containerOp->type->type) {
            $userType = $containerOp->type->userType ?? '';
            if ('' !== $userType && 'object' !== strtolower(ltrim($userType, '\\'))) {
                return strtolower(ltrim($userType, '\\'));
            }
        }

        return null;
    }

    private static function stringDataPtrFromLiteral(Context $context, string $message): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($message),
            $context->getTypeFromString('int8*')
        );
    }
}
