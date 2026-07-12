<?php
declare(strict_types=1);
namespace PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPLLVM\Value;
final class RuntimeInitVmContext {
    public static function emit(Context $context, ObjectType $object, Value $runtimeThis): void {
        $ctxId = $object->lookup('PHPCompiler\\VM\\Context');
        $ctx = $object->allocate($ctxId);
        $object->markObjectConstructed($ctx);

        foreach ([
            'functions',
            'classes',
            'classAliases',
            'enums',
            'classAutoloaders',
            'splAutoloadCallbacks',
            'loadedCompileUnits',
            'constants',
            'superglobalVars',
            'globalVars',
            'functionStaticVars',
            'functionStaticInitialized',
            'foreachIterators',
        ] as $prop) {
            $slot = $object->propertyFetch($ctx, 'PHPCompiler\\VM\\Context', $prop);
            $emptyHt = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                HashTableHelper::alloc($context)
            );
            $object->propertyStore($slot->objectPropertySlot, $emptyHt, Variable::TYPE_HASHTABLE);
        }

        $errorsId = $object->lookup('PHPCompiler\\VM\\ErrorReporter');
        $errors = $object->allocate($errorsId);
        $object->markObjectConstructed($errors);
        $errorsVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $errors);
        $errorsSlot = $object->propertyFetch($ctx, 'PHPCompiler\\VM\\Context', 'errors');
        $object->propertyStore($errorsSlot->objectPropertySlot, $errorsVar, Variable::TYPE_OBJECT);

        $stackId = $object->lookup('PHPCompiler\\VM\\ScriptStack');
        $scriptStack = $object->allocate($stackId);
        $object->markObjectConstructed($scriptStack);
        $stackVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $scriptStack);
        $stackSlot = $object->propertyFetch($ctx, 'PHPCompiler\\VM\\Context', 'scriptStack');
        $object->propertyStore($stackSlot->objectPropertySlot, $stackVar, Variable::TYPE_OBJECT);

        $exceptionId = $object->lookup('PHPCompiler\\VM\\ExceptionHandlerStack');
        $exceptionHandlers = $object->allocate($exceptionId);
        $object->markObjectConstructed($exceptionHandlers);
        $exceptionVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $exceptionHandlers);
        $exceptionSlot = $object->propertyFetch($ctx, 'PHPCompiler\\VM\\Context', 'exceptionHandlers');
        $object->propertyStore($exceptionSlot->objectPropertySlot, $exceptionVar, Variable::TYPE_OBJECT);

        $limitsId = $object->lookup('PHPCompiler\\ext\\standard\\VmExecutionLimits');
        $executionLimits = $object->allocate($limitsId);
        $object->markObjectConstructed($executionLimits);
        $limitsVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $executionLimits);
        $limitsSlot = $object->propertyFetch($ctx, 'PHPCompiler\\VM\\Context', 'executionLimits');
        $object->propertyStore($limitsSlot->objectPropertySlot, $limitsVar, Variable::TYPE_OBJECT);

        $runtimeVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $runtimeThis);
        $runtimeSlot = $object->propertyFetch($ctx, 'PHPCompiler\\VM\\Context', 'runtime');
        $object->propertyStore($runtimeSlot->objectPropertySlot, $runtimeVar, Variable::TYPE_OBJECT);
        $ctxVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $ctx);
        $vmContextSlot = $object->propertyFetch($runtimeThis, 'PHPCompiler\\Runtime', 'vmContext');
        $object->propertyStore($vmContextSlot->objectPropertySlot, $ctxVar, Variable::TYPE_OBJECT);
        VmActiveContextLlvm::ensureGlobal($context);
        VmActiveContextLlvm::storeContext($context, $ctx);
    }
}
