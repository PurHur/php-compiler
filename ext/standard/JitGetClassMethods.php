<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_class_methods() (issue #3118, runtime class name #4752). */
final class JitGetClassMethods
{
    private const TYPE_ERROR =
        'get_class_methods(): Argument #1 ($object_or_class) must be of type object|string, %s given';

    private static int $seq = 0;

    public static function invoke(Context $context, JITVariable $classArg): Value
    {
        $filter = VmReflection::METHOD_FILTER_DEFAULT;
        $compileTimeEnum = $classArg->compileTimeEnumCase ?? null;
        if (\is_array($compileTimeEnum) && isset($compileTimeEnum['classId'])) {
            $object = $context->type->object;
            if ($object instanceof ObjectBuiltin) {
                return self::invokeForClassName(
                    $context,
                    $object->classNameForId((int) $compileTimeEnum['classId']),
                    $filter
                );
            }
        }
        if (JITVariable::TYPE_OBJECT === $classArg->type) {
            return self::invokeForObject($context, $classArg, $filter);
        }

        $literal = JitStringArg::compileTimeLiteral($classArg);
        if (null !== $literal) {
            return self::invokeForClassName($context, $literal, $filter);
        }

        if (JITVariable::TYPE_VALUE === $classArg->type) {
            return self::invokeFromValueBox($context, $classArg, $filter);
        }

        if (JITVariable::TYPE_STRING === $classArg->type) {
            return self::invokeForRuntimeClassNameString(
                $context,
                $context->helper->loadValue($classArg),
                $filter
            );
        }

        self::emitTypeErrorAndAbort($context, self::scalarTypeError($classArg->type));

        return self::returnFalse($context);
    }

    private static function invokeFromValueBox(
        Context $context,
        JITVariable $classArg,
        int $filter
    ): Value {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $classArg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_ENUM_CASE, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );

        $nullBlock = BasicBlockHelper::append($context, 'gcm_null');
        $notNull = BasicBlockHelper::append($context, 'gcm_not_null');
        $enumBlock = BasicBlockHelper::append($context, 'gcm_enum');
        $objectCheck = BasicBlockHelper::append($context, 'gcm_obj_check');
        $objectBlock = BasicBlockHelper::append($context, 'gcm_obj');
        $notObject = BasicBlockHelper::append($context, 'gcm_not_obj');
        $stringBlock = BasicBlockHelper::append($context, 'gcm_str');
        $errBlock = BasicBlockHelper::append($context, 'gcm_err');
        $mergeBlock = BasicBlockHelper::append($context, 'gcm_merge');

        $context->builder->branchIf($isNull, $nullBlock, $notNull);

        $context->builder->positionAtEnd($nullBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'null'));

        $context->builder->positionAtEnd($notNull);
        $context->builder->branchIf($isEnumCase, $enumBlock, $objectCheck);

        $context->builder->positionAtEnd($enumBlock);
        $enumResult = self::invokeForEnumCaseValueBox($context, $valuePtr, $filter);
        $enumEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($objectCheck);
        $context->builder->branchIf($isObject, $objectBlock, $notObject);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $obj
        );
        $objResult = self::invokeForObject($context, $objVar, $filter);
        $objEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($notObject);
        $context->builder->branchIf($isString, $stringBlock, $errBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVal = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $strResult = self::invokeForRuntimeClassNameString($context, $strVal, $filter);
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'mixed'));

        $context->builder->positionAtEnd($mergeBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($enumResult, $enumEnd);
        $phi->addIncoming($objResult, $objEnd);
        $phi->addIncoming($strResult, $strEnd);

        return $phi;
    }

    private static function invokeForEnumCaseValueBox(Context $context, Value $enumCasePtr, int $filter): Value
    {
        $object = $context->type->object;
        if (!$object instanceof ObjectBuiltin) {
            return self::returnFalse($context);
        }
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            return self::returnFalse($context);
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($enumCasePtr, $enumMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return self::returnFalse($context);
        }

        return self::invokeForClassName(
            $context,
            $object->classNameForId((int) $classIdVal->getConstantValue()),
            $filter
        );
    }

    private static function invokeForRuntimeClassNameString(
        Context $context,
        Value $nameStr,
        int $filter
    ): Value {
        $object = $context->type->object;
        /** @var list<string> $candidates */
        $candidates = array_values($object->allClassNamesById());
        $vm = $context->runtime->vmContext;
        if (null !== $vm) {
            foreach ($vm->classes as $entry) {
                if (!\in_array($entry->name, $candidates, true)) {
                    $candidates[] = $entry->name;
                }
            }
        }
        if ([] === $candidates) {
            return self::returnFalse($context);
        }

        $tag = 'gcm_rt_'.(string) ++self::$seq;
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $falseBlock = BasicBlockHelper::append($context, $tag.'_false');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        /** @var list<array{0: \PHPLLVM\BasicBlock, 1: Value}> $incoming */
        $incoming = [];

        $nameData = JitClassExists::stringDataPtr($context, $nameStr);
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $i32 = $context->getTypeFromString('int32');

        $lastIdx = \count($candidates) - 1;
        foreach ($candidates as $idx => $className) {
            $lc = strtolower(ltrim($className, '\\'));
            $candidate = $context->builder->load($context->constantStringFromString($lc));
            $candidateData = JitClassExists::stringDataPtr($context, $candidate);
            $cmp = $context->builder->call($strcasecmpFn, $nameData, $candidateData);
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $i32->constInt(0, false)
            );
            $matchBlock = BasicBlockHelper::append($context, $tag.'_match_'.$idx);
            $nextBlock = $lastIdx === $idx
                ? $falseBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$idx);
            $context->builder->branchIf($isMatch, $matchBlock, $nextBlock);
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

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function scalarTypeError(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return \sprintf(self::TYPE_ERROR, 'int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return \sprintf(self::TYPE_ERROR, 'float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return \sprintf(self::TYPE_ERROR, 'bool');
            case JITVariable::TYPE_NULL:
                return \sprintf(self::TYPE_ERROR, 'null');
            default:
                return \sprintf(self::TYPE_ERROR, 'mixed');
        }
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
        return self::invokeCompileTimeForClassName($context, $className, $filter);
    }

    private static function invokeCompileTimeForClassName(Context $context, string $className, int $filter): Value
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = $context->type->object;
        if ($object->hasUserDeclaredClass($className)
            || $object->isInterfaceClassLc($lc)
            || $object->hasUserDeclaredEnum($className)) {
            $names = $object->allMethodNamesForClassId($object->lookup($className), $filter);

            return self::buildIndexedStringArray($context, $names);
        }

        $vm = $context->runtime->vmContext;
        if (null !== $vm && isset($vm->classes[$lc])) {
            return self::buildIndexedStringArray(
                $context,
                VmReflection::classMethodsList($vm->classes[$lc], $filter, $vm)
            );
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
