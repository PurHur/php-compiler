<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\PropertyVisibility;
use PHPLLVM\Type;
use PHPLLVM\Value\Function_;

/**
 * Static property accessed via -> / ?-> (#30017).
 *
 * VM emits Zend E_NOTICE via {@see \PHPCompiler\VM\ErrorReporter::accessingStaticPropertyAsNonStatic}.
 * JIT enforces inaccessible-static visibility Errors; Notice for JIT/AOT is deferred because
 * mid-body __compiler_trigger_error(E_NOTICE) SEGVs under MCJIT without a user handler
 * (also reproduces on master for this shape).
 *
 * php-src: Zend/zend_object_handlers.c — zend_get_property_offset ZEND_ACC_STATIC.
 */
final class StaticPropertyAsNonStaticJitGuard
{
    public const CONTINUE = 0;
    public const HANDLED_ERROR = 2;

    /**
     * @return int self::CONTINUE | self::HANDLED_ERROR
     */
    public static function emitBeforeInstanceFetch(
        Object_ $objectType,
        \PHPCompiler\JIT $jit,
        Block $block,
        int $classId,
        string $className,
        string $propName,
        bool $silent
    ): int {
        $meta = $objectType->staticPropertyVisibilityMeta($classId, $propName);
        if (null === $meta) {
            return self::CONTINUE;
        }
        $declId = (int) $meta['declaringClassId'];
        $vis = (int) $meta['visibility'];
        if (
            ($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0
            && $declId !== $classId
        ) {
            return self::CONTINUE;
        }
        $context = $objectType->jitContext();
        $declaringLc = strtolower(ltrim((string) $meta['declaringClassName'], '\\'));
        $callerLc = self::callerClassLc($context, $block);
        $getVisibility = (int) ($meta['getVisibility'] ?? 0);
        try {
            PropertyVisibility::assertAccessible(
                $vis,
                $callerLc,
                $declaringLc,
                $className,
                $propName,
                strtolower(ltrim($className, '\\')),
                static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent),
                $getVisibility
            );
        } catch (\LogicException $e) {
            if ($silent) {
                return self::CONTINUE;
            }
            self::emitViolation($context, $jit, $e->getMessage());

            return self::HANDLED_ERROR;
        }

        return self::CONTINUE;
    }

    private static function callerClassLc(Context $context, ?Block $enclosingBlock): ?string
    {
        if (null !== $enclosingBlock?->func?->class) {
            return strtolower(ltrim($enclosingBlock->func->class->value, '\\'));
        }
        if ('' !== $context->scope->className) {
            return strtolower(ltrim($context->scope->className, '\\'));
        }

        return null;
    }

    private static function emitViolation(Context $context, \PHPCompiler\JIT $jit, string $message): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);

        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Function_);
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            return;
        }

        $failBlock = $fn->appendBasicBlock('static_as_instance_vis_violation');
        $continueBlock = $fn->appendBasicBlock('static_as_instance_vis_continue');

        $context->builder->positionAtEnd($entry);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($failBlock);
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
        } else {
            ErrorRaise::emitRaise($context, $message);
            self::returnAfterPendingError($context, $fn);
        }

        $context->builder->positionAtEnd($continueBlock);
    }

    private static function returnAfterPendingError(Context $context, Function_ $fn): void
    {
        if (BasicBlockHelper::isVoidLlvmFunctionValue($fn)) {
            $context->builder->returnVoid();

            return;
        }
        $fnType = BasicBlockHelper::llvmFunctionSignatureType($fn);
        if (null !== $fnType) {
            $returnType = $fnType->getReturnType();
            if (Type::KIND_POINTER === $returnType->getKind()) {
                $context->builder->returnValue($returnType->constNull());

                return;
            }
            if (Type::KIND_INTEGER === $returnType->getKind()) {
                $context->builder->returnValue($returnType->constInt(0, false));

                return;
            }
            $structName = $context->getStringFromType($returnType);
            if ('__value__' === $structName) {
                $slot = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                $context->builder->returnValue($context->builder->load($slot));

                return;
            }
        }
        $context->builder->returnVoid();
    }

    private static function isSubclassOf(Object_ $objectType, string $childLc, string $parentLc): bool
    {
        $childLc = strtolower(ltrim($childLc, '\\'));
        $parentLc = strtolower(ltrim($parentLc, '\\'));
        $current = $childLc;
        for ($depth = 0; $depth < 64; ++$depth) {
            if ($current === $parentLc) {
                return true;
            }
            $parent = $objectType->parentClassLc($current);
            if (null === $parent) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }
}
