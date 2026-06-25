<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\VM\VmBoundMethodCallable;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ClosureWithBinding;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\MethodVisibility;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;

/**
 * SSOT for TYPE_FROM_CALLABLE JIT lowering (#4810, #10272).
 *
 * php-src: Zend/zend_closures.c — Closure::fromCallable(), first-class callable
 * php-src: Zend/zend_compile.c — ZEND_AST_CALLABLE_CONVERT
 *
 * VM runtime: {@see ClosureSupport::fromCallable()}
 */
final class VmFromCallable
{
    public static function createClosureVariable(Context $context, Block $block, OpCode $op): JitVariable
    {
        $callableSlot = (int) $op->arg2;
        if (isset($block->constants[$callableSlot])) {
            $const = $block->constants[$callableSlot];
            if (VmVariable::TYPE_STRING === $const->type) {
                return self::fromStringCallable($context, $block, $const->toString());
            }
        }

        $methodLc = VmBoundMethodCallable::resolveMethodLcFromCalleeSlot($block, $callableSlot);
        if (null !== $methodLc) {
            $receiverOp = VmBoundMethodCallable::resolveBoundMethodReceiverOperand($block, $callableSlot);
            if (null !== $receiverOp) {
                $classHint = VmBoundMethodCallable::resolveBoundMethodReceiverClassName($block, $callableSlot);

                return self::fromBoundMethodCallable($context, $block, $receiverOp, $methodLc, $classHint);
            }
        }

        $receiverOp = VmBoundMethodCallable::resolveInvokableObjectReceiverOperand($block, $callableSlot);
        if (null !== $receiverOp) {
            $classHint = VmBoundMethodCallable::resolveInvokableObjectClassName($block, $callableSlot);

            return self::fromBoundMethodCallable($context, $block, $receiverOp, '__invoke', $classHint);
        }

        throw new \LogicException('TYPE_FROM_CALLABLE: unsupported callable form in JIT');
    }

    private static function fromStringCallable(Context $context, Block $block, string $name): JitVariable
    {
        if (str_starts_with($name, 'new ')) {
            throw new \LogicException('Cannot create Closure for new expression');
        }

        if (str_contains($name, '::')) {
            [$className, $methodName] = explode('::', $name, 2);
            $declaringClassLc = strtolower($className);
            $methodLc = strtolower($methodName);
            self::assertStaticMethodFcc($context, $declaringClassLc, $methodLc, $className, $methodName);
            $proxyName = self::resolveStaticProxyName($context, $block, $declaringClassLc, $methodLc, $className, $methodName);
            $proxy = $context->resolveFunctionProxy($proxyName);

            return self::wrapCallableProxy($context, $proxy);
        }

        $lc = strtolower($name);
        if (!$context->functionIsRegistered($lc)) {
            throw new \LogicException("Call to undefined function {$lc}()");
        }

        return self::wrapCallableProxy($context, $context->resolveFunctionProxy($lc));
    }

    /** FCC closures invoke via {@see JitVariable::$closureCall}, not TARGET_PROPERTY indirection. */
    private static function wrapCallableProxy(Context $context, Call $proxy): JitVariable
    {
        $classId = $context->type->object->lookup('Closure');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        $var = new JitVariable($context, JitVariable::TYPE_OBJECT, JitVariable::KIND_VALUE, $obj);
        $var->closureCall = $proxy;

        return $var;
    }

    private static function fromBoundMethodCallable(
        Context $context,
        Block $block,
        \PHPCfg\Operand $receiverOp,
        string $methodLc,
        ?string $classHint = null
    ): JitVariable {
        // Enum case FCC receivers often have userType '' while classHint is the enum FQCN (#6845, #9250).
        $className = self::nonEmptyString($receiverOp->type?->userType)
            ?? self::nonEmptyString($classHint)
            ?? ($context->scope->className !== '' ? $context->scope->className : 'object');
        $declaringClassLc = strtolower(ltrim((string) $className, '\\'));
        $proxyName = self::resolveInstanceProxyName($context, $declaringClassLc, $methodLc, $className);
        $inner = $context->resolveFunctionProxy($proxyName);
        $receiverVar = $context->getVariableFromOp($receiverOp);
        $scopeName = self::nonEmptyString($receiverOp->type?->userType)
            ?? self::nonEmptyString($classHint)
            ?? $className;
        $scopeConst = $context->context->constString((string) $scopeName, true);
        $boundScope = new JitVariable(
            $context,
            JitVariable::TYPE_STRING,
            JitVariable::KIND_VALUE,
            $scopeConst
        );
        $boundScope->compileTimeString = (string) $scopeName;
        $closureCall = new ClosureWithBinding($inner, $receiverVar, $boundScope);
        $closureVar = self::wrapCallableProxy($context, $closureCall);

        return $closureVar;
    }

    /** Zend zend_compile_first_class_callable: reject instance methods on Class::m(...) (#7465). */
    private static function assertStaticMethodFcc(
        Context $context,
        string $calledClassLc,
        string $methodLc,
        string $calledClassName,
        string $methodDisplay
    ): void {
        if ($context->type->object->isEnumClassLc(strtolower(ltrim($calledClassLc, '\\')))
            && 'cases' === $methodLc) {
            return;
        }
        $visited = [];
        $current = strtolower(ltrim($calledClassLc, '\\'));
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            if ($context->type->object->hasDeclaredClass($current)) {
                $classId = $context->type->object->lookup($current);
                if ($context->type->object->hasMethod($classId, $methodLc)) {
                    $vis = $context->type->object->methodVisibility($classId, $methodLc);
                    if (0 === ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                        $declaringName = $context->type->object->classNameForId($classId);
                        throw new \Error(
                            'Non-static method '.$declaringName.'::'.$methodDisplay.'() cannot be called statically'
                        );
                    }

                    return;
                }
            }
            $parent = $context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }
    }

    private static function resolveStaticProxyName(
        Context $context,
        Block $block,
        string $declaringClassLc,
        string $methodLc,
        string $className,
        string $methodName
    ): string {
        $declaringClassId = $context->type->object->lookup($className);
        $visFlags = $context->type->object->methodVisibility($declaringClassId, $methodLc);
        $callerClassLc = null;
        if (null !== $block->func && null !== $block->func->class) {
            $callerClassLc = strtolower($block->func->class->value);
        } elseif ($context->scope->className !== '') {
            $callerClassLc = $context->scope->className;
        }
        MethodVisibility::assertCallable(
            $visFlags,
            $callerClassLc,
            $declaringClassLc,
            $className,
            $methodName,
            false
        );
        $proxyName = strtolower($className.'::'.$methodName);
        if (!$context->functionIsRegistered($proxyName)) {
            throw new \LogicException("Call to undefined static method {$className}::{$methodName}()");
        }

        return $proxyName;
    }

    private static function resolveInstanceProxyName(
        Context $context,
        string $declaringClassLc,
        string $methodLc,
        string $className
    ): string {
        if ('object' === $declaringClassLc) {
            if ('getname' === $methodLc && $context->functionIsRegistered('reflectionattribute::getname')) {
                $declaringClassLc = 'reflectionattribute';
                $className = 'ReflectionAttribute';
            } elseif ('getattributes' === $methodLc && $context->functionIsRegistered('reflectionmethod::getattributes')) {
                $declaringClassLc = 'reflectionmethod';
                $className = 'ReflectionMethod';
            }
        }
        $proxyName = strtolower($className.'::'.$methodLc);
        if (!$context->functionIsRegistered($proxyName)) {
            throw new \LogicException("Call to undefined method {$className}::{$methodLc}()");
        }

        return $proxyName;
    }

    private static function nonEmptyString(?string $value): ?string
    {
        return null !== $value && '' !== $value ? $value : null;
    }
}
