<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/** LLVM lowering for class_constants() (issue #7309). */
final class JitClassConstants
{
    public static function invoke(Context $context, JITVariable $classArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($classArg);
        if (null === $literal) {
            throw new \LogicException(
                'class_constants() class name must be a string literal in this compiler build'
            );
        }

        return self::wrapHashTable($context, self::invokeForClassName($context, $literal));
    }

    private static function invokeForClassName(Context $context, string $className): Value
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = $context->type->object;
        if ($object->hasUserDeclaredClass($className)) {
            if ($object->isTraitClass($lc)) {
                throw new \LogicException("Cannot fetch constants from trait {$className}");
            }

            return self::emitFromObjectRegistry($context, $className);
        }

        $vm = $context->runtime->vmContext;
        if (null !== $vm && isset($vm->classes[$lc])) {
            $entry = VmReflection::fetchClassEntryForClassConstants($vm, $className);

            return self::emitFromVmClass($context, $entry);
        }

        throw new \LogicException('Class "'.$className.'" not found');
    }

    private static function emitFromVmClass(Context $context, \PHPCompiler\VM\ClassEntry $entry): Value
    {
        $table = VmReflection::classConstantsArray($context->runtime->vmContext, $entry)->toArray();
        $ht = HashTableHelper::alloc($context);
        foreach ($table->iterate(false) as $key => $valueVar) {
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            self::storeVmVariable($context, $ht, $keyStr, $valueVar);
        }

        return $ht;
    }

    private static function emitFromObjectRegistry(Context $context, string $className): Value
    {
        $object = $context->type->object;
        $classId = $object->lookup($className);
        $ht = HashTableHelper::alloc($context);
        foreach ($object->classConstantsForId($classId) as [$key, $_entry]) {
            $displayName = $object->classConstDisplayName($classId, $key);
            $keyStr = $context->builder->load($context->constantStringFromString($displayName));
            $jit = $object->classConstFetch($classId, $key, null, $className);
            HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);
        }

        return $ht;
    }

    private static function storeVmVariable(
        Context $context,
        Value $ht,
        Value $keyStr,
        VMVariable $value
    ): void {
        $resolved = $value->resolveIndirect();
        switch ($resolved->type) {
            case VMVariable::TYPE_INTEGER:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_BOOLEAN:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt($resolved->toBool() ? 1 : 0, false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_FLOAT:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_DOUBLE,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('double')->constReal($resolved->toFloat())
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_STRING:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString($resolved->toString()))
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_OBJECT:
                if (EnumCaseSupport::isEnumCase($resolved->toObject())) {
                    throw new \LogicException(
                        'class_constants() enum case objects require compile-time class registry in this compiler build'
                    );
                }

                return;
            case VMVariable::TYPE_ENUM_CASE:
                throw new \LogicException(
                    'class_constants() enum cases require compile-time class registry in this compiler build'
                );
            default:
                return;
        }
    }

    private static function wrapHashTable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
