<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\Variable;
use PHPLLVM;
use PHPLLVM\Value;

/**
 * LLVM lowering for __object__invoke_destructor (#9938).
 */
final class ObjectDestructorLlvm
{
    public static function implementInvokeDestructor(Object_ $object): void
    {
        $context = $object->jitContext();
        $objPtr = $context->getTypeFromString('__object__*');
        $void = $context->getTypeFromString('void');
        $fnType = $context->context->functionType($void, false, $objPtr);
        $fn = $context->module->getNamedFunction('__object__invoke_destructor');
        if (null !== $fn && $fn->countBasicBlocks() > 0) {
            return;
        }
        if (null === $fn) {
            $fn = $context->module->addFunction('__object__invoke_destructor', $fnType);
            $context->registerFunction('__object__invoke_destructor', $fn);
        }
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $classIds = $object->classIdsWithDestructor();
        if ([] === $classIds) {
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();

            return;
        }
        $constructed = $context->builder->load(
            $context->builder->structGep($obj, $context->structFieldMap['__object__']['constructed'])
        );
        $notReady = $fn->appendBasicBlock('destruct_not_constructed');
        $ready = $fn->appendBasicBlock('destruct_ready');
        $done = $fn->appendBasicBlock('destruct_done');
        $isReady = $context->builder->icmp(
            PHPLLVM\Builder::INT_NE,
            $constructed,
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        $context->builder->branchIf($isReady, $ready, $notReady);
        $context->builder->positionAtEnd($notReady);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($ready);
        self::emitDestructDispatchForObject($object, $fn, $obj, $classIds, $done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param list<int> $classIds
     */
    public static function emitDestructDispatchForObject(
        Object_ $object,
        Value\Function_ $fn,
        Value $obj,
        array $classIds,
        PHPLLVM\BasicBlock $outerDone
    ): void {
        $context = $object->jitContext();
        $objMap = $context->structFieldMap['__object__'];
        $classIdVal = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        if (1 === \count($classIds)) {
            $onlyId = $classIds[0];
            $callBlock = $fn->appendBasicBlock('destruct_magic_single_call');
            $skipBlock = $fn->appendBasicBlock('destruct_magic_single_skip');
            $expected = $context->constantFromInteger($onlyId, 'int64');
            $isId = $context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classIdVal, $expected);
            $context->builder->branchIf($isId, $callBlock, $skipBlock);
            $context->builder->positionAtEnd($callBlock);
            self::emitDestructMagicCallForClass($object, $obj, $onlyId);
            $context->builder->branch($outerDone);
            $context->builder->positionAtEnd($skipBlock);
            $context->builder->branch($outerDone);

            return;
        }
        $done = $fn->appendBasicBlock('destruct_magic_done');
        $fallback = $fn->appendBasicBlock('destruct_magic_unknown');
        $caseBlocks = [];
        foreach ($classIds as $id) {
            $caseBlocks[] = $fn->appendBasicBlock('destruct_magic_class_'.$id);
        }
        $checkBlock = $context->builder->getInsertBlock();
        foreach ($classIds as $i => $id) {
            $context->builder->positionAtEnd($checkBlock);
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classIdVal, $expected);
            $nextCheck = $i + 1 < \count($classIds)
                ? $fn->appendBasicBlock('destruct_magic_try_'.($i + 1))
                : $fallback;
            $context->builder->branchIf($isId, $caseBlocks[$i], $nextCheck);
            $context->builder->positionAtEnd($caseBlocks[$i]);
            self::emitDestructMagicCallForClass($object, $obj, $id);
            $context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($fallback);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->branch($outerDone);
    }

    public static function emitDestructMagicCallForClass(Object_ $object, Value $obj, int $classId): void
    {
        $context = $object->jitContext();
        $className = $object->classNameForId($classId);
        $proxyName = strtolower($className).'::'.'__destruct';
        if (!$context->functionIsRegistered($proxyName)) {
            return;
        }
        $refVirtual = $context->builder->pointerCast(
            $obj,
            $context->getTypeFromString('__ref__virtual*')
        );
        $context->refcount->addref($refVirtual);
        $context->builder->call(
            $context->lookupFunction('phpc_destruct_set_allow_delref'),
            $context->getTypeFromString('int32')->constInt(0, false)
        );
        $objVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $obj
        );
        $toCall = $context->resolveFunctionProxy($proxyName);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = false;
        $toCall->call($context, $objVar);
        $context->callerStrictTypes = $prevStrict;
    }
}
