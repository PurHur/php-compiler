<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PseudoClassScope;
use PHPCompiler\VM\ErrorReporter;

/**
 * Closure::fromCallable / bind / bindTo helpers (issue #3266, #3673, Zend zend_closures.c).
 */
final class ClosureSupport
{
    public static function requireClosureClass(Context $ctx): ClassEntry
    {
        $class = $ctx->classes['closure'] ?? null;
        if (null === $class) {
            throw new \LogicException('Closure is not registered in this compiler build');
        }

        return $class;
    }

    public static function requireClosureState(ObjectEntry $object, string $context): ClosureState
    {
        $state = $object->closureState;
        if (null === $state) {
            throw new \LogicException("{$context} expects a Closure instance");
        }

        return $state;
    }

    public static function wrapState(Context $ctx, ClosureState $state): ObjectEntry
    {
        return $state->wrapObject($ctx);
    }

    /**
     * @return list<Variable>
     */
    public static function callerArgsForVisibility(Frame $frame): array
    {
        if (!empty($frame->callArgs)) {
            return $frame->callArgs;
        }
        if (!empty($frame->calledArgs)) {
            return $frame->calledArgs;
        }

        return [];
    }

    public static function callerClassLc(Frame $frame): ?string
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (null === $f->block || null === $f->block->func || null === $f->block->func->class) {
                continue;
            }

            return strtolower($f->block->func->class->value);
        }

        return null;
    }

    public static function fromCallable(Context $ctx, Frame $frame, Variable $callable): ObjectEntry
    {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_OBJECT === $callable->type) {
            $state = $callable->toObject()->closureState;
            if (null !== $state) {
                return $callable->toObject();
            }
        }
        if (Variable::TYPE_STRING === $callable->type) {
            $name = $callable->toString();
            if (str_contains($name, '::')) {
                return self::wrapState($ctx, self::fromStaticStringCallable($ctx, $frame, $name));
            }

            return self::wrapState($ctx, self::fromFunctionName($ctx, $name));
        }
        if (Variable::TYPE_ARRAY === $callable->type) {
            return self::wrapState($ctx, self::fromArrayCallable($ctx, $frame, $callable));
        }

        throw new \LogicException(
            'Closure::fromCallable(): Argument #1 ($callback) must be a valid callback'
        );
    }

    public static function bindTo(
        Context $ctx,
        ClosureState $state,
        Variable $newThis,
        ?Variable $newScope,
        string $context = 'Closure::bindTo()',
        ?Frame $frame = null
    ): ?ObjectEntry {
        $newThis = $newThis->resolveIndirect();
        if (Variable::TYPE_NULL !== $newThis->type && Variable::TYPE_OBJECT !== $newThis->type) {
            $thisArg = 'Closure::bind()' === $context ? '#2 ($newThis)' : '#1 ($newThis)';
            throw new \TypeError(
                "{$context}: Argument {$thisArg} must be of type ?object, "
                .self::valueTypeName($newThis).' given'
            );
        }
        if (null !== $state->wrappedFunc || null !== $state->methodName) {
            return null;
        }
        if (Variable::TYPE_OBJECT === $newThis->type && $state->isStaticClosure()) {
            throw new \Error('Cannot bind static closure to object');
        }
        $bound = $state->cloneForBind();
        if (Variable::TYPE_NULL === $newThis->type) {
            $bound->boundThis = null;
        } else {
            $bound->boundThis = $newThis;
        }
        $scopeClass = self::resolveScopeClass($newScope, $newThis, $context);
        if (null !== $scopeClass && self::isInternalScopeClass($ctx, $scopeClass)) {
            self::warnCannotBindInternalScope($ctx, $frame, $scopeClass);

            return null;
        }
        $bound->boundScopeClass = $scopeClass;

        return self::wrapState($ctx, $bound);
    }

    private static function isInternalScopeClass(Context $ctx, string $scopeClass): bool
    {
        $lc = strtolower($scopeClass);
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($scopeClass);
        }
        if (!isset($ctx->classes[$lc])) {
            return false;
        }

        return $ctx->classes[$lc]->isInternal;
    }

    private static function warnCannotBindInternalScope(
        Context $ctx,
        ?Frame $frame,
        string $scopeClass
    ): void {
        $display = $scopeClass;
        $lc = strtolower($scopeClass);
        if (isset($ctx->classes[$lc])) {
            $display = $ctx->classes[$lc]->name;
        }
        $ctx->errors->triggerError(
            "Cannot bind closure to scope of internal class {$display}",
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );
    }

    private static function fromFunctionName(Context $ctx, string $name): ClosureState
    {
        $lc = strtolower($name);
        if (!isset($ctx->functions[$lc])) {
            throw new \LogicException(
                "Closure::fromCallable(): Function '{$name}' not found"
            );
        }

        return ClosureState::fromWrappedFunc($ctx->functions[$lc]);
    }

    private static function fromStaticStringCallable(Context $ctx, Frame $frame, string $callable): ClosureState
    {
        [$className, $methodName] = explode('::', $callable, 2);
        $lcClass = self::resolveClassScopeName($className, $frame, $ctx);
        if (!isset($ctx->classes[$lcClass])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lcClass])) {
            throw new \LogicException(
                "Closure::fromCallable(): Class '{$className}' not found"
            );
        }
        $methodLc = strtolower($methodName);
        [$class, $methodLc] = self::resolveStaticMethod($ctx, $lcClass, $methodLc);
        $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = self::callerClassLc($frame);
        $callerDisplay = self::classDisplayName($ctx, $callerClassLc);
        MethodVisibility::assertCallable(
            $vis,
            $callerClassLc,
            strtolower($class->name),
            $class->name,
            $class->methodNames[$methodLc] ?? $methodName,
            false,
            fn (string $classLc, string $ancestorLc): bool => self::isClassSameOrSubclassOf($ctx, $classLc, $ancestorLc),
            $callerDisplay
        );

        return ClosureState::fromWrappedFunc($class->methods[$methodLc]);
    }

    private static function fromArrayCallable(Context $ctx, Frame $frame, Variable $callable): ClosureState
    {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \LogicException(
                'Closure::fromCallable(): Argument #1 ($callback) must be a valid callback'
            );
        }
        $receiver = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect()->toString();
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return self::fromInstanceMethodCallable($ctx, $frame, $receiver, $methodName);
        }
        if (Variable::TYPE_STRING === $receiver->type) {
            return self::fromStaticStringCallable(
                $ctx,
                $frame,
                $receiver->toString().'::'.$methodName
            );
        }

        throw new \LogicException(
            'Closure::fromCallable(): Argument #1 ($callback) must be a valid callback'
        );
    }

    private static function fromInstanceMethodCallable(
        Context $ctx,
        Frame $frame,
        Variable $receiver,
        string $methodName
    ): ClosureState {
        $object = $receiver->toObject();
        $methodLc = strtolower($methodName);
        $class = $object->class;
        [$declaringClass, $methodLc] = self::resolveStaticMethod($ctx, strtolower($class->name), $methodLc);
        $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = self::callerClassLc($frame);
        $callerDisplay = self::classDisplayName($ctx, $callerClassLc);
        MethodVisibility::assertCallable(
            $vis,
            $callerClassLc,
            strtolower($declaringClass->name),
            $declaringClass->name,
            $declaringClass->methodNames[$methodLc] ?? $methodName,
            false,
            fn (string $classLc, string $ancestorLc): bool => self::isClassSameOrSubclassOf($ctx, $classLc, $ancestorLc),
            $callerDisplay
        );
        $boundThis = new Variable();
        $boundThis->copyFrom($receiver);
        $state = ClosureState::fromMethodCallable($declaringClass->methods[$methodLc], $boundThis, $methodName);
        $state->boundScopeClass = $class->name;

        return $state;
    }

    private static function resolveScopeClass(
        ?Variable $newScope,
        Variable $newThis,
        string $context = 'Closure::bindTo()'
    ): ?string {
        $scopeArg = 'Closure::bind()' === $context ? '#3 ($newScope)' : '#2 ($newScope)';
        if (null === $newScope) {
            if (Variable::TYPE_OBJECT === $newThis->type) {
                return $newThis->toObject()->class->name;
            }

            return null;
        }
        $newScope = $newScope->resolveIndirect();
        if (Variable::TYPE_NULL === $newScope->type) {
            if (Variable::TYPE_OBJECT === $newThis->type) {
                return $newThis->toObject()->class->name;
            }

            return null;
        }
        if (Variable::TYPE_OBJECT === $newScope->type) {
            return $newScope->toObject()->class->name;
        }
        if (Variable::TYPE_STRING === $newScope->type) {
            $scope = $newScope->toString();
            if ('static' === strtolower($scope)) {
                if (Variable::TYPE_OBJECT === $newThis->type) {
                    return $newThis->toObject()->class->name;
                }

                return null;
            }

            return $scope;
        }

        throw new \TypeError(
            "{$context}: Argument {$scopeArg} must be of type object|string|null, "
            .self::valueTypeName($newScope).' given'
        );
    }

    private static function valueTypeName(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

    private static function resolveClassScopeName(string $className, Frame $frame, Context $ctx): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass) {
            if (null === $frame->block->func || null === $frame->block->func->class) {
                PseudoClassScope::fatalInGlobalScope('self');
            }

            return strtolower($frame->block->func->class->value);
        }
        if ('static' === $lcClass) {
            if (null !== $frame->calledClass && '' !== $frame->calledClass) {
                return strtolower($frame->calledClass);
            }
            if (null === $frame->block->func || null === $frame->block->func->class) {
                PseudoClassScope::fatalInGlobalScope('static');
            }

            return strtolower($frame->block->func->class->value);
        }
        if ('parent' === $lcClass) {
            if (null === $frame->block->func || null === $frame->block->func->class) {
                PseudoClassScope::fatalInGlobalScope('parent');
            }
            $declaring = strtolower($frame->block->func->class->value);
            if (!isset($ctx->classes[$declaring])) {
                PseudoClassScope::fatalInGlobalScope('parent');
            }
            $parentLc = $ctx->classes[$declaring]->parentLc;
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parentLc;
        }

        return $lcClass;
    }

    /**
     * @return array{0: ClassEntry, 1: string}
     */
    private static function resolveStaticMethod(Context $ctx, string $lcClass, string $methodLc): array
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($ctx->classes[$lcClass])) {
                break;
            }
            $class = $ctx->classes[$lcClass];
            if (isset($class->methods[$methodLc])) {
                return [$class, $methodLc];
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        throw new \LogicException("Call to undefined static method {$lcClass}::{$methodLc}()");
    }

    private static function classDisplayName(Context $ctx, ?string $classLc): ?string
    {
        if (null === $classLc || !isset($ctx->classes[$classLc])) {
            return null;
        }

        return $ctx->classes[$classLc]->name;
    }

    private static function isClassSameOrSubclassOf(Context $ctx, string $classLc, string $ancestorLc): bool
    {
        $current = $classLc;
        while (true) {
            if ($current === $ancestorLc) {
                return true;
            }
            if (!isset($ctx->classes[$current])) {
                return false;
            }
            $parentLc = $ctx->classes[$current]->parentLc;
            if (null === $parentLc) {
                return false;
            }
            $current = $parentLc;
        }
    }
}
