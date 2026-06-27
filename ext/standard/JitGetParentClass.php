<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_parent_class() (issue #3483). */
final class JitGetParentClass
{
    private const OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR =
        'get_parent_class(): Argument #1 ($object_or_class) must be an object or a valid class name, %s given';

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
            return self::invokeForBoxedValue($context, $whatArg);
        }

        throw new \LogicException(
            'get_parent_class() class name must be a string literal in this compiler build'
        );
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $template, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, \sprintf($template, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function invokeForBoxedValue(Context $context, JITVariable $whatArg): Value
    {
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
        $tag = 'gpc_box_'.(string) ++self::$seq;
        $enumCaseBlock = BasicBlockHelper::append($context, $tag.'_enum');
        $objectBlock = BasicBlockHelper::append($context, $tag.'_obj');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($isEnumCase, $enumCaseBlock, $objectBlock);

        $context->builder->positionAtEnd($enumCaseBlock);
        $falsePtr = self::returnFalse($context);
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
        $objectOkBlock = BasicBlockHelper::append($context, $tag.'_obj_ok');
        $invalidBlock = BasicBlockHelper::append($context, $tag.'_invalid');
        $context->builder->branchIf($isObject, $objectOkBlock, $invalidBlock);

        $context->builder->positionAtEnd($invalidBlock);
        self::emitJitTypeErrorAndAbort($context, self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR, 'mixed');

        $context->builder->positionAtEnd($objectOkBlock);
        $objVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $obj
        );
        $objectResult = self::invokeForObject($context, $objVar);
        $objectEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $enumCaseBlock);
        $result->addIncoming($objectResult, $objectEndBlock);

        return $result;
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
