<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for class_uses() (issue #3119). */
final class JitClassUses
{
    private static int $seq = 0;

    public static function invoke(Context $context, JITVariable $whatArg, bool $autoload): Value
    {
        if (JITVariable::TYPE_OBJECT === $whatArg->type) {
            return self::invokeForObject($context, $whatArg, $autoload);
        }

        $literal = JitStringArg::compileTimeLiteral($whatArg);
        if (null !== $literal) {
            return self::invokeForClassName($context, $literal, $autoload);
        }

        if (JITVariable::TYPE_VALUE === $whatArg->type) {
            return self::invokeForBoxedValue($context, $whatArg, $autoload);
        }

        throw new \LogicException(
            'class_uses() class name must be a string literal in this compiler build'
        );
    }

    private static function invokeForBoxedValue(
        Context $context,
        JITVariable $whatArg,
        bool $autoload
    ): Value {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $whatArg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $tag = 'cu_box_'.(string) ++self::$seq;
        $enumCaseBlock = BasicBlockHelper::append($context, $tag.'_enum');
        $objectBlock = BasicBlockHelper::append($context, $tag.'_obj');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($isEnumCase, $enumCaseBlock, $objectBlock);

        $context->builder->positionAtEnd($enumCaseBlock);
        $emptyPtr = self::returnEmptyArray($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objType = $context->getTypeFromString('__object__*');
        $isObject = $context->builder->icmp(
            Builder::INT_NE,
            $obj,
            $objType->constNull()
        );
        if (!$isObject) {
            throw new \LogicException(
                'class_uses() argument must be an object or class name string in this compiler build'
            );
        }
        $objVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $obj
        );
        $objectResult = self::invokeForObject($context, $objVar, $autoload);
        $objectEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($emptyPtr, $enumCaseBlock);
        $result->addIncoming($objectResult, $objectEndBlock);

        return $result;
    }

    private static function invokeForObject(
        Context $context,
        JITVariable $objectArg,
        bool $autoload
    ): Value {
        $obj = JITVariable::TYPE_OBJECT === $objectArg->type
            ? $context->helper->loadValue($objectArg)
            : $objectArg->value;
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $object = $context->type->object;
        $names = $object->allClassNamesById();
        if ([] === $names) {
            return self::returnFalse($context);
        }

        $tag = 'cu_obj_'.(string) ++self::$seq;
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $falseBlock = BasicBlockHelper::append($context, $tag.'_false');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        /** @var list<array{0: \PHPLLVM\BasicBlock, 1: Value}> $incoming */
        $incoming = [];

        $ids = array_keys($names);
        $lastIdx = \count($ids) - 1;
        foreach ($ids as $idx => $id) {
            $className = $names[$id];
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $matchBlock = BasicBlockHelper::append($context, $tag.'_match_'.$id);
            $nextBlock = $lastIdx === $idx
                ? $falseBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$id);
            $context->builder->branchIf($isClass, $matchBlock, $nextBlock);
            $context->builder->positionAtEnd($matchBlock);
            $ptr = self::invokeForClassName($context, $className, $autoload);
            $incoming[] = [$context->builder->getInsertBlock(), $ptr];
            $context->builder->branch($done);
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($falseBlock);
        $falsePtr = self::returnFalse($context);
        $incoming[] = [$falseBlock, $falsePtr];
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $result = $context->builder->phi($valuePtrTy);
        foreach ($incoming as [$block, $ptr]) {
            $result->addIncoming($ptr, $block);
        }

        return $result;
    }

    private static function invokeForClassName(Context $context, string $className, bool $autoload): Value
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = $context->type->object;
        if ($object->isInterfaceClassLc($lc)) {
            return self::returnFalse($context);
        }
        if ($object->hasUserDeclaredEnum($className)) {
            return self::returnEmptyArray($context);
        }
        if ($object->isTraitClass($lc) || $object->hasUserDeclaredClass($className)) {
            return self::buildTraitMapFromNames(
                $context,
                self::traitNamesForClass($context, $className, $lc)
            );
        }

        $vm = $context->runtime->vmContext;
        if (null !== $vm && isset($vm->classes[$lc])) {
            $entry = $vm->classes[$lc];
            if ($entry->isInterface) {
                return self::returnFalse($context);
            }
            if ($entry->isEnum) {
                return self::returnEmptyArray($context);
            }

            return self::buildTraitMapFromNames(
                $context,
                array_values(VmReflection::traitUsesMap($entry))
            );
        }

        if (!$autoload) {
            return self::returnFalse($context);
        }

        return self::returnFalse($context);
    }

    /**
     * @return list<string>
     */
    private static function traitNamesForClass(Context $context, string $className, string $classLc): array
    {
        $object = $context->type->object;
        if (!$object->hasUserDeclaredClass($className)
            && !$object->isTraitClass($classLc)) {
            return [];
        }

        return $object->usedTraitNamesForClassLc($classLc);
    }

    /**
     * @param list<string> $traitNames
     */
    private static function buildTraitMapFromNames(Context $context, array $traitNames): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($traitNames as $traitName) {
            $keyStr = $context->builder->load($context->constantStringFromString($traitName));
            $val = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($traitName))
            );
            HashTableHelper::setAtStringKey($context, $ht, $keyStr, $val);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function returnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $ptr;
    }

    private static function returnEmptyArray(Context $context): Value
    {
        return self::buildTraitMapFromNames($context, []);
    }
}
