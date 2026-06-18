<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\Compiler\EnumBackedCaseCheck;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPLLVM\Type;
use PHPLLVM\Value\Function_;

/**
 * JIT: duplicate backed enum values throw catchable Error at first case fetch (#5773, #9255).
 *
 * php-src: Zend/zend_enum.c — zend_enum_build_backed_enum_table
 */
final class BackedEnumDuplicateJitGuard
{
    public static function emitBeforeEnumCaseFetch(
        Object_ $objectType,
        ?\PHPCompiler\JIT $jit,
        ?Block $block,
        int $classId
    ): void {
        $message = $objectType->duplicateBackedEnumErrorMessage($classId);
        if (null === $message) {
            return;
        }

        $context = $objectType->jitContext();
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);

        $fn = $context->builder->getInsertBlock()?->getParent();
        if (!$fn instanceof Function_) {
            return;
        }
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            return;
        }

        $failBlock = $fn->appendBasicBlock('enum_dup_backing_violation');
        $continueBlock = $fn->appendBasicBlock('enum_dup_backing_continue');

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

    public static function duplicateMessageForCases(string $enumName, array $cases): ?string
    {
        return EnumBackedCaseCheck::duplicateBackingErrorMessage($enumName, $cases);
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
}
