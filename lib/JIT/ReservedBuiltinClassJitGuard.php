<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\VM\ReservedBuiltinClass;
use PHPLLVM\Type;
use PHPLLVM\Value\Function_;

/**
 * JIT: reserved internal classes reject userland `new` (#13324).
 *
 * php-src: Zend/zend_closures.c, Zend/zend_generators.c, ext/curl/interface.c
 */
final class ReservedBuiltinClassJitGuard
{
    public static function emitBeforeAllocate(
        Object_ $objectType,
        ?\PHPCompiler\JIT $jit,
        ?Block $block,
        int $classId
    ): void {
        $className = $objectType->classNameForId($classId);
        $message = ReservedBuiltinClass::userInstantiationErrorMessage($className);
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

        $failBlock = $fn->appendBasicBlock('reserved_class_new_violation');
        $continueBlock = $fn->appendBasicBlock('reserved_class_new_continue');

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
}
