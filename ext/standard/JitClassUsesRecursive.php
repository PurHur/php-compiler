<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringClassUsesRecursive;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for class_uses_recursive() (issue #6469). */
final class JitClassUsesRecursive
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

        return self::routeThroughPhpHelper($context, $whatArg, $autoload);
    }

    private static function routeThroughPhpHelper(
        Context $context,
        JITVariable $whatArg,
        bool $autoload
    ): Value {
        $operandPtr = self::operandToValueBox($context, $whatArg);
        $i1 = $context->getTypeFromString('int1');
        $autoloadVal = $autoload ? $i1->constInt(1, false) : $i1->constInt(0, false);

        return StringClassUsesRecursive::invoke($context, $operandPtr, $autoloadVal);
    }

    private static function operandToValueBox(Context $context, JITVariable $whatArg): Value
    {
        if (JITVariable::TYPE_VALUE === $whatArg->type) {
            return JitValueBox::valuePtrFromVariable($context, $whatArg);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (JITVariable::TYPE_OBJECT === $whatArg->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($whatArg)
            );
        } elseif (JITVariable::TYPE_STRING === $whatArg->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $context->helper->loadValue($whatArg)
            );
        } else {
            throw new \LogicException(
                'class_uses_recursive() class name must be a string literal or object in this compiler build'
            );
        }

        return $ptr;
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

        $tag = 'cur_obj_'.(string) ++self::$seq;
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
        if ($object->isInterfaceClassLc($lc) || $object->hasUserDeclaredEnum($className)) {
            return self::returnFalse($context);
        }
        if ($object->isTraitClass($lc) || $object->hasUserDeclaredClass($className)) {
            return self::buildTraitMapFromNames(
                $context,
                self::recursiveTraitNamesForClass($context, $className, $lc)
            );
        }

        $vm = $context->runtime->vmContext;
        if (null !== $vm && isset($vm->classes[$lc])) {
            $entry = $vm->classes[$lc];
            if ($entry->isInterface || $entry->isEnum) {
                return self::returnFalse($context);
            }

            return self::buildTraitMapFromNames(
                $context,
                array_values(VmReflection::traitUsesRecursiveMap($vm, $entry))
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
    private static function recursiveTraitNamesForClass(Context $context, string $className, string $classLc): array
    {
        $object = $context->type->object;
        if (!$object->hasUserDeclaredClass($className)
            && !$object->isTraitClass($classLc)) {
            return [];
        }

        $result = [];
        self::collectRecursiveJit($context, $classLc, $result);

        return array_keys($result);
    }

    /**
     * @param array<string, true> $result
     */
    private static function collectRecursiveJit(Context $context, string $classLc, array &$result): void
    {
        $object = $context->type->object;
        foreach ($object->usedTraitNamesForClassLc($classLc) as $traitName) {
            $result[$traitName] = true;
            $traitLc = strtolower(ltrim($traitName, '\\'));
            self::collectRecursiveJit($context, $traitLc, $result);
        }
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
}
