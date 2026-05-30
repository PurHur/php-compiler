<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\MethodRegistry;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_class_methods() (issue #3118). */
final class JitGetClassMethods
{
    private static int $seq = 0;

    public static function invoke(Context $context, JITVariable $classArg, int $filter): Value
    {
        if (JITVariable::TYPE_OBJECT === $classArg->type) {
            return self::invokeForObject($context, $classArg, $filter);
        }

        $literal = JitStringArg::compileTimeLiteral($classArg);
        if (null === $literal) {
            if (JITVariable::TYPE_VALUE === $classArg->type) {
                $valuePtr = JitValueBox::valuePtrFromVariable($context, $classArg);
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
                        'get_class_methods() argument must be an object or class name string in this compiler build'
                    );
                }
                $objVar = new JITVariable(
                    $context,
                    JITVariable::TYPE_OBJECT,
                    JITVariable::KIND_VALUE,
                    $obj
                );

                return self::invokeForObject($context, $objVar, $filter);
            }
            throw new \LogicException(
                'get_class_methods() class name must be a string literal in this compiler build'
            );
        }

        return self::invokeForClassName($context, $literal, $filter);
    }

    private static function invokeForObject(
        Context $context,
        JITVariable $objectArg,
        int $filter
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

        $tag = 'gcm_obj_'.(string) ++self::$seq;
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
            $ptr = self::invokeForClassName($context, $className, $filter);
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

    private static function invokeForClassName(Context $context, string $className, int $filter): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return self::invokeNativeForClassName($context, $className, $filter);
        }

        return self::invokeCompileTimeForClassName($context, $className, $filter);
    }

    private static function invokeNativeForClassName(Context $context, string $className, int $filter): Value
    {
        MethodRegistry::registerDeclarations($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $classLc = strtolower(ltrim($className, '\\'));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('phpc_get_class_methods'),
            $context->builder->pointerCast($context->constantFromString($classLc), $i8p),
            $i32->constInt($filter, false),
            $ptr
        );

        return $ptr;
    }

    private static function invokeCompileTimeForClassName(Context $context, string $className, int $filter): Value
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = $context->type->object;
        if ($object->hasUserDeclaredClass($className)
            || $object->isInterfaceClassLc($lc)
            || $object->hasUserDeclaredEnum($className)) {
            $names = $object->allMethodNamesForClassId($object->lookup($className), $filter);
            if ([] !== $names) {
                return self::buildIndexedStringArray($context, $names);
            }
        }

        $vm = $context->runtime->vmContext;
        if (null !== $vm && isset($vm->classes[$lc])) {
            return self::buildIndexedStringArray($context, VmReflection::classMethodsList($vm->classes[$lc], $filter));
        }

        return self::returnFalse($context);
    }

    /**
     * @param list<string> $methodNames
     */
    private static function buildIndexedStringArray(Context $context, array $methodNames): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        foreach ($methodNames as $index => $methodName) {
            $val = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($methodName))
            );
            HashTableHelper::setAtIndex($context, $ht, $i64->constInt($index, false), $val);
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
