<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPLLVM\Builder;

/**
 * Emit readonly-class and readonly-property instance write checks before JIT property stores (#1360, #3432).
 */
final class ReadonlyClassGuard
{
    public static function emitBeforePropertyStore(
        Context $context,
        Variable $lvalue,
        ?Block $enclosingBlock,
        string $violation = 'modify'
    ): void {
        if (null === $lvalue->objectPropertySlot) {
            return;
        }
        $objectType = $context->type->object;
        assert($objectType instanceof Object_);
        if (null === $lvalue->objectPropertyReceiver && null !== $lvalue->objectPropertySlot) {
            $lvalue->objectPropertyReceiver = $objectType->receiverForPropertySlot($lvalue->objectPropertySlot);
        }
        if (null === $lvalue->objectPropertyReceiver) {
            return;
        }
        if ('modify' === $violation && self::isConstructBlock($enclosingBlock)) {
            return;
        }

        $propName = $lvalue->objectPropertyName ?? 'property';
        $guardClassIds = array_values(array_unique(array_merge(
            $objectType->readonlyClassIds(),
            $objectType->readonlyPropertyClassIdsForProperty($propName)
        )));
        if ([] === $guardClassIds) {
            return;
        }

        $obj = $lvalue->objectPropertyReceiver;
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $storeBlock = $fn->appendBasicBlock('readonly_allow_store');
        $exitBlock = $fn->appendBasicBlock('readonly_guard_exit');

        $checkBlock = $entry;
        foreach ($guardClassIds as $i => $id) {
            $matchBlock = $fn->appendBasicBlock('readonly_match_'.$id);
            $nextCheck = $i + 1 < count($guardClassIds)
                ? $fn->appendBasicBlock('readonly_try_'.($i + 1))
                : $storeBlock;
            $context->builder->positionAtEnd($checkBlock);
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $context->builder->branchIf($isId, $matchBlock, $nextCheck);

            $context->builder->positionAtEnd($matchBlock);
            $failBlock = $fn->appendBasicBlock('readonly_violation_'.$id);
            $context->builder->branch($failBlock);
            $context->builder->positionAtEnd($failBlock);
            $declaringClass = $objectType->classNameForId($id);
            $message = sprintf(
                'unset' === $violation
                    ? 'Cannot unset readonly property %s::$%s'
                    : 'Cannot modify readonly property %s::$%s',
                $declaringClass,
                $propName
            );
            $msgLen = $context->constantFromInteger(strlen($message), 'size_t');
            $msgCStr = self::stringDataPtrFromLiteral($context, $message);
            $context->builder->call(
                $context->lookupFunction('__compiler_jit_raise_logic_exception'),
                $msgCStr,
                $msgLen
            );
            $context->builder->returnVoid();
            $checkBlock = $nextCheck;
        }

        $context->builder->positionAtEnd($storeBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
    }

    private static function isConstructBlock(?Block $block): bool
    {
        if (null === $block || null === $block->func) {
            return false;
        }
        $name = strtolower($block->func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
    }

    private static function stringDataPtrFromLiteral(Context $context, string $message): \PHPLLVM\Value
    {
        // Use php_cstr_* rodata (MethodRegistry / M3Emit pattern) — heap __string__ value GEP
        // can yield a bad memcpy source for raise_logic_exception under MCJIT execute (#3149).
        return $context->builder->pointerCast(
            $context->constantFromString($message),
            $context->getTypeFromString('int8*')
        );
    }
}
