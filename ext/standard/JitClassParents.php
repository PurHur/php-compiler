<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for class_parents() (issue #3159). */
final class JitClassParents
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
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $whatArg);
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
                    'class_parents() argument must be an object or class name string in this compiler build'
                );
            }
            $objVar = new JITVariable(
                $context,
                JITVariable::TYPE_OBJECT,
                JITVariable::KIND_VALUE,
                $obj
            );

            return self::invokeForObject($context, $objVar, $autoload);
        }

        throw new \LogicException(
            'class_parents() class name must be a string literal in this compiler build'
        );
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

        $tag = 'cp_obj_'.(string) ++self::$seq;
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
        if ($object->hasUserDeclaredClass($className)) {
            return self::buildParentsFromObjectRegistry($context, $className);
        }

        $vm = $context->runtime->vmContext;
        if (null !== $vm && isset($vm->classes[$lc])) {
            $entry = $vm->classes[$lc];
            if ($entry->isTrait || $entry->isInterface || $entry->isEnum) {
                return self::returnFalse($context);
            }

            return self::buildParentsFromNames(
                $context,
                VmReflection::classParentsList($entry, $vm)
            );
        }

        if (!$autoload) {
            return self::returnFalse($context);
        }

        return self::returnFalse($context);
    }

    private static function buildParentsFromObjectRegistry(Context $context, string $className): Value
    {
        $object = $context->type->object;
        $names = [];
        $current = $className;
        while (true) {
            $parent = $object->parentClassDisplayName($current);
            if (null === $parent) {
                break;
            }
            $names[] = $parent;
            $current = $parent;
        }

        return self::buildParentsFromNames($context, $names);
    }

    /**
     * @param list<string> $parentNames
     */
    private static function buildParentsFromNames(Context $context, array $parentNames): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($parentNames as $index => $parentName) {
            $jit = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($parentName))
            );
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $context->getTypeFromString('int64')->constInt($index, false),
                $jit
            );
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
