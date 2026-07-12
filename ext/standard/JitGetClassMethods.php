<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGetClassMethods;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for get_class_methods() via GetClassMethodsJitHelper PHP (#3118, #16729).
 *
 * Compile-time literal class names keep registry fast path; runtime operands route through PHP.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_class_methods)
 */
final class JitGetClassMethods
{
    private const TYPE_ERROR =
        'get_class_methods(): Argument #1 ($object_or_class) must be of type object|string, %s given';

    private const OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR =
        'get_class_methods(): Argument #1 ($object_or_class) must be an object or a valid class name, %s given';

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

        $literal = JitStringArg::compileTimeLiteral($classArg);
        if (null !== $literal) {
            return self::invokeForClassName($context, $literal, $filter);
        }

        if (JITVariable::TYPE_OBJECT === $classArg->type) {
            return self::invokeForObject($context, $classArg, $filter);
        }

        if (JITVariable::TYPE_STRING === $classArg->type
            || JITVariable::TYPE_VALUE === $classArg->type) {
            return self::routeThroughPhpHelper($context, $classArg);
        }

        self::emitTypeErrorAndAbort($context, self::scalarTypeError($classArg->type));

        return self::returnFalse($context);
    }

    private static function routeThroughPhpHelper(Context $context, JITVariable $classArg): Value
    {
        return StringGetClassMethods::invoke($context, self::operandToValueBox($context, $classArg));
    }

    private static function operandToValueBox(Context $context, JITVariable $classArg): Value
    {
        if (JITVariable::TYPE_VALUE === $classArg->type) {
            return JitValueBox::valuePtrFromVariable($context, $classArg);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->helper->loadValue($classArg)
        );

        return $ptr;
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

    private static function invokeForClassName(Context $context, string $className, int $filter): Value
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

        self::emitTypeErrorAndAbort($context, \sprintf(self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR, 'string'));

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
