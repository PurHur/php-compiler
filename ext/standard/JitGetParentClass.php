<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_parent_class() (issue #3483). */
final class JitGetParentClass
{
    private static int $seq = 0;

    public static function invoke(Context $context, JITVariable $whatArg): Value
    {
        if (JITVariable::TYPE_OBJECT === $whatArg->type) {
            return self::invokeForObject($context, $whatArg);
        }

        $literal = JitStringArg::compileTimeLiteral($whatArg);
        if (null !== $literal) {
            return self::invokeForClassName($context, $literal);
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
                    'get_parent_class() argument must be an object or class name string in this compiler build'
                );
            }
            $objVar = new JITVariable(
                $context,
                JITVariable::TYPE_OBJECT,
                JITVariable::KIND_VALUE,
                $obj
            );

            return self::invokeForObject($context, $objVar);
        }

        throw new \LogicException(
            'get_parent_class() class name must be a string literal in this compiler build'
        );
    }

    private static function invokeForObject(Context $context, JITVariable $objectArg): Value
    {
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

        $tag = 'gpc_obj_'.(string) ++self::$seq;
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
            $ptr = self::invokeForClassName($context, $className);
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

    private static function invokeForClassName(Context $context, string $className): Value
    {
        $parentName = self::resolveParentDisplayName($context, $className);
        if (null === $parentName) {
            return self::returnFalse($context);
        }

        return self::returnString($context, $parentName);
    }

    private static function resolveParentDisplayName(Context $context, string $className): ?string
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = $context->type->object;
        if ($object->hasUserDeclaredClass($className)) {
            return $object->parentClassDisplayName($className);
        }

        $vm = $context->runtime->vmContext;
        if (null === $vm || !isset($vm->classes[$lc])) {
            return null;
        }
        $entry = $vm->classes[$lc];
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
            return null;
        }

        return VmReflection::parentClassName($entry, $vm);
    }

    private static function returnString(Context $context, string $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->builder->load($context->constantStringFromString($value))
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
