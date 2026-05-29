<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';

use PHPCompiler\Func;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\NamedArgs;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

class VM {
    const SUCCESS = 1;
    const FAILURE = 2;

    /** Generator body suspended at `yield` (issue #167). */
    const GENERATOR_YIELD = 3;

    public Context $context;

    public function __construct(Context $context) {
        $this->context = $context;
    }

    public function run(Block $block): int {
        if (!is_null($block->handler)) {
            $frame = $block->getFrame($this->context);
            $this->seedScriptPath($frame);
            $block->handler->execute($frame);
            return self::SUCCESS;
        }

        $frame = $block->getFrame($this->context);
        $this->seedScriptPath($frame);
        $this->context->push($frame);

        $result = $this->runFrames();
        if ('' !== $frame->scriptPath) {
            $this->context->scriptStack->pop();
        }

        return $result;
    }

    /**
     * Invoke a user-defined PHP function from a VM builtin (isolated run stack).
     */
    public function invokePhpFunction(Func\PHP $func, Variable ...$args): Variable
    {
        $savedStack = $this->context->swapRunStack(null);
        try {
            $child = $func->getFrame($this->context, null);
            $child->calledArgs = $args;
            if (
                [] !== $args
                && null !== $func->block->func
                && null !== $func->block->func->class
            ) {
                $thisIdx = $func->block->slotIndexForVariableName('this');
                if (null !== $thisIdx) {
                    $child->scope[$thisIdx] = $args[0];
                }
            }
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('User function invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * Walk inheritance for an instance method (Zend zend_object_handlers parity, #3259).
     *
     * @return array{0: ClassEntry, 1: string}
     */
    public function resolveInstanceMethod(ClassEntry $class, string $methodLc): array
    {
        return $this->resolveStaticMethod(strtolower($class->name), strtolower($methodLc));
    }

    public function hasInstanceMethod(ClassEntry $class, string $methodLc): bool
    {
        $methodLc = strtolower($methodLc);
        $lcClass = strtolower($class->name);
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                return false;
            }
            $entry = $this->context->classes[$lcClass];
            if (isset($entry->methods[$methodLc])) {
                return true;
            }
            if (null === $entry->parentLc) {
                return false;
            }
            $lcClass = $entry->parentLc;
        }

        return false;
    }

    /** Invoke a user instance method from VM internals (e.g. __debugInfo, #3259). */
    public function invokeInstanceMethod(ObjectEntry $object, string $methodName): Variable
    {
        $methodLc = strtolower($methodName);
        [$declaring] = $this->resolveInstanceMethod($object->class, $methodLc);
        $func = $declaring->methods[$methodLc];
        if (!$func instanceof Func\PHP) {
            throw new \LogicException("{$declaring->name}::{$methodName}() is not a user method in this compiler build");
        }
        $thisVar = new Variable();
        $thisVar->object($object);
        return $this->invokePhpFunction($func, $thisVar);
    }

    /**
     * Properties for var_dump / print_r when __debugInfo is defined (Zend parity, #3259).
     *
     * @return array<string, Variable>
     */
    public function getObjectDebugProperties(ObjectEntry $object): array
    {
        if ($this->hasInstanceMethod($object->class, '__debuginfo')) {
            $result = $this->invokeInstanceMethod($object, '__debugInfo')->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $result->type) {
                throw new \LogicException(
                    "{$object->class->name}::__debugInfo() must return an array in this compiler build"
                );
            }
            $props = [];
            foreach ($result->toArray()->iterateKeyed(true) as [$key, $value]) {
                $name = Variable::TYPE_STRING === $key->type
                    ? $key->toString()
                    : (string) $key->toInt();
                $copy = new Variable();
                $copy->copyFrom($value->resolveIndirect());
                $props[$name] = $copy;
            }

            return $props;
        }

        return $object->class->getProperties($object->getRawProperties(), ClassEntry::PROP_PURPOSE_DEBUG);
    }

    /**
     * Invoke a closure from a VM builtin (isolated run stack; issue #72).
     */
    public function invokeClosure(ClosureState $closureState, Variable ...$args): Variable
    {
        $savedStack = $this->context->swapRunStack(null);
        try {
            $child = $closureState->func->getFrame($this->context, null);
            $this->bindClosureCallCaptures($child, $closureState);
            $child->calledArgs = $args;
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Closure invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * Compile and execute a PHP file once (require_once semantics for manifest includes / PSR-4).
     */
    public function executeCompileUnit(string $path): void
    {
        $resolved = VM\ScriptStack::normalize($path);
        if ('' === $resolved || !is_file($resolved)) {
            return;
        }
        if ($this->context->isCompileUnitLoaded($resolved)) {
            return;
        }
        $this->context->markCompileUnitLoaded($resolved);

        $savedStack = $this->context->swapRunStack(null);
        try {
            $this->context->scriptStack->push($resolved);
            $block = $this->context->runtime->parseAndCompileFile($resolved);
            if (null === $block) {
                return;
            }
            $this->run($block);
        } finally {
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * Materialize a Traversable (array or Generator) into a new array (ext/spl iterator_to_array parity, #3100).
     */
    public function iteratorToArray(Variable $iterator, bool $preserveKeys = false): HashTable
    {
        $iterator = $iterator->resolveIndirect();
        $out = new HashTable();
        if (Variable::TYPE_ARRAY === $iterator->type) {
            $index = 0;
            foreach ($iterator->toArray()->iterateKeyed(true) as [$key, $value]) {
                if ($preserveKeys) {
                    self::appendHashTableEntry($out, $key, $value);
                } else {
                    $packedKey = new Variable();
                    $packedKey->int($index++);
                    self::appendHashTableEntry($out, $packedKey, $value);
                }
            }

            return $out;
        }
        if ($this->variableIsGenerator($iterator)) {
            $gen = $iterator->toObject()->generatorState;
            $gen->rewind();
            $index = 0;
            while ($this->advanceGeneratorIteration($gen)) {
                if ($preserveKeys) {
                    self::appendHashTableEntry($out, $gen->currentKey, $gen->currentValue);
                } else {
                    $packedKey = new Variable();
                    $packedKey->int($index++);
                    self::appendHashTableEntry($out, $packedKey, $gen->currentValue);
                }
            }

            return $out;
        }

        throw new \LogicException(
            'iterator_to_array() argument must be an array or Generator in this compiler build'
        );
    }

    private static function appendHashTableEntry(HashTable $out, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        if (Variable::TYPE_INTEGER === $key->type) {
            $out->append($copy);

            return;
        }
        $out->add($key->toString(), $copy);
    }

    private function seedScriptPath(Frame $frame): void
    {
        if ('' !== $frame->scriptPath) {
            $this->context->scriptStack->push($frame->scriptPath);
        }
    }

    private function runFrames(): int
    {
nextframe:
        $frame = $this->context->pop();

        if (is_null($frame)) {
            return self::SUCCESS;
        }
restart:
        if ($this->context->pendingReturnDispatch) {
            $this->context->pendingReturnDispatch = false;
            $frame = $this->context->pendingReturnResumeFrame;
            $isVoid = $this->context->pendingReturnIsVoid;
            $returnValue = $this->context->pendingReturnValue;
            $this->clearPendingReturnState();
            if ($isVoid) {
                goto return_void_complete;
            }
            goto return_value_complete;
        }

        while ($frame->pos < $frame->block->nOpCodes) {
            $op = $frame->block->opCodes[$frame->pos++];
            switch ($op->type) {
                case OpCode::TYPE_TYPE_ASSERT:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg1->copyFrom($arg2); 
                    break;
                case OpCode::TYPE_ASSIGN:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    if (null !== ($err = $this->enforceReadonlyPropertyWrite($arg2, $frame))) {
                        return $err;
                    }
                    $arg2->copyFrom($arg3);
                    $arg1->copyFrom($arg3);
                    $strict = null !== $frame->parent
                        ? $frame->parent->block->strictTypes
                        : $frame->block->strictTypes;
                    TypeCheck::coercePropertyWrite($arg2, $strict);
                    break;
                case OpCode::TYPE_ASSIGN_REF:
                    $lhs = $frame->scope[$op->arg1];
                    if (null !== ($err = $this->enforceReadonlyPropertyWrite($lhs, $frame))) {
                        return $err;
                    }
                    $rhs = $frame->scope[$op->arg2]->resolveIndirect();
                    $lhs->indirect($rhs);
                    break;
                case OpCode::TYPE_VAR_FETCH:
                    $dest = $frame->scope[$op->arg1];
                    $name = $frame->scope[$op->arg2]->resolveIndirect()->toString();
                    if (Superglobals::isSuperglobalName($name)) {
                        $target = $this->context->ensureGlobal($name);
                    } else {
                        $target = $frame->block->findVariableByRuntimeName($name, $frame);
                        if (null === $target) {
                            return $this->raise("Undefined variable \${$name}", $frame);
                        }
                    }
                    $dest->indirect($target);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Global name must be a compile-time constant');
                    }
                    $globalName = $frame->block->constants[$op->arg2]->toString();
                    $frame->scope[$op->arg1]->indirect($this->context->ensureGlobal($globalName));
                    break;
                case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $storageKey = $frame->block->constants[$op->arg2]->toString();
                    $storage = $this->context->ensureFunctionStatic($storageKey);
                    if (!$this->context->isFunctionStaticInitialized($storageKey)) {
                        if (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                            $storage->copyFrom($frame->block->constants[$op->arg3]);
                        }
                        $this->context->markFunctionStaticInitialized($storageKey);
                    }
                    $frame->scope[$op->arg1]->indirect($storage);
                    break;
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_ARRAY_DIM_FETCH_WRITE:
                    $arg1 = $frame->scope[$op->arg1];
                    $container = $frame->scope[$op->arg2]->resolveIndirect();
                    $forWrite = OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type;
                    if (is_null($op->arg3)) {
                        if ($container->type !== Variable::TYPE_ARRAY) {
                            throw new \LogicException('[] is only supported for arrays');
                        }
                        $arg1->indirect($container->toArray()->append(new Variable));
                        break;
                    }
                    $arg3 = $frame->scope[$op->arg3];
                    if ($container->type === Variable::TYPE_STRING) {
                        $offset = new Variable(Variable::TYPE_STRING_OFFSET);
                        $offset->stringOffset($container, $arg3->toInt());
                        $arg1->indirect($offset);
                    } elseif ($container->type === Variable::TYPE_ARRAY) {
                        $table = $container->toArray();
                        if (!$forWrite && !$table->keyExists($arg3)) {
                            $this->context->errors->undefinedArrayKey(
                                $arg3,
                                $this->context,
                                $frame,
                                '' !== $frame->scriptPath ? $frame->scriptPath : null
                            );
                        }
                        $arg1->indirect($table->findVariable($arg3, $forWrite));
                    } else {
                        throw new \LogicException('Illegal offset');
                    }
                    break;
                case OpCode::TYPE_CAST_BOOL:
                    $frame->scope[$op->arg1]->castFrom(Variable::TYPE_BOOLEAN, $frame->scope[$op->arg2]);
                    break;
                case OpCode::TYPE_CAST_INT:
                    $frame->scope[$op->arg1]->castFrom(Variable::TYPE_INTEGER, $frame->scope[$op->arg2]);
                    break;
                case OpCode::TYPE_CAST_STRING:
                    $frame->scope[$op->arg1]->castFrom(Variable::TYPE_STRING, $frame->scope[$op->arg2]);
                    break;
                case OpCode::TYPE_CAST_OBJECT:
                    $dst = $frame->scope[$op->arg1];
                    $src = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_OBJECT === $src->type) {
                        $dst->copyFrom($src);
                        break;
                    }
                    if (!isset($this->context->classes['stdclass'])) {
                        throw new \LogicException('stdClass is not registered');
                    }
                    $object = new VM\ObjectEntry($this->context->classes['stdclass']);
                    $object->constructed = true;
                    $dst->object($object);
                    if (Variable::TYPE_ARRAY === $src->type) {
                        foreach ($src->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                            $propName = $keyVar->is(Variable::TYPE_INTEGER)
                                ? (string) $keyVar->toInt()
                                : $keyVar->toString();
                            $object->getProperty($propName)->copyFrom($valueVar);
                        }
                    }
                    break;
                case OpCode::TYPE_IDENTICAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bool($arg2->identicalTo($arg3));
                    break;
                case OpCode::TYPE_NOT_IDENTICAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bool(!$arg2->identicalTo($arg3));
                    break;
                case OpCode::TYPE_EQUAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bool($arg2->equals($arg3));
                    break;
                case OpCode::TYPE_NOT_EQUAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bool(!$arg2->equals($arg3));
                    break;
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->compareOp($op->type, $arg2, $arg3);
                    break;
                case OpCode::TYPE_SPACESHIP:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->spaceshipOp($arg2, $arg3);
                    break;
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_POW:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->numericOp($op->type, $arg2, $arg3);
                    break;
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bitwiseOp($op->type, $arg2, $arg3);
                    break;

                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_UNARY_PLUS:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg1->unaryOp($op->type, $arg2);
                    break;
                case OpCode::TYPE_CONCAT:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2]->toString();
                    $arg3 = $frame->scope[$op->arg3]->toString();
                    $arg1->string($arg2 . $arg3);
                    break;
                case OpCode::TYPE_ECHO:
                    VM\OutputBuffer::append($frame->scope[$op->arg1]->toString());
                    break;
                case OpCode::TYPE_PRINT:
                    VM\OutputBuffer::append($frame->scope[$op->arg2]->toString());
                    $frame->scope[$op->arg1]->int(1);
                    break;
                case OpCode::TYPE_COALESCE:
                    $takeLeft = $frame->scope[$op->arg2]->toBool();
                    $frame = ($takeLeft ? $op->block1 : $op->block2)->getFrame(
                        $this->context,
                        $frame
                    );
                    goto restart;
                case OpCode::TYPE_NULLSAFE:
                    $receiver = $frame->scope[$op->arg2]->resolveIndirect();
                    $frame = (
                        Variable::TYPE_NULL === $receiver->type
                            ? $op->block1
                            : $op->block2
                    )->getFrame($this->context, $frame);
                    goto restart;
                case OpCode::TYPE_EXIT:
                    $exitArg = null;
                    if (null !== $op->arg2) {
                        $exitArg = $frame->scope[$op->arg2];
                    }
                    ext\standard\VmExit::terminate($exitArg);
                    break;
                case OpCode::TYPE_JUMP:
                    $this->markFinallyCompletedWhenLeavingFinallyBody($frame);
                    $finallyFrame = $this->continueReturnFinallyChain();
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    if ($this->schedulePendingReturnDispatch()) {
                        goto restart;
                    }
                    $resumeFrame = $this->resumeCatchAfterFinally($frame);
                    if (null !== $resumeFrame) {
                        $frame = $resumeFrame;
                        goto restart;
                    }
                    $frame = $this->frameForBranch($frame, $op->block1);
                    goto restart;
                case OpCode::TYPE_JUMPIF:
                    $arg1 = $frame->scope[$op->arg1]->toBool();
                    $branchTarget = $arg1 ? $op->block1 : $op->block2;
                    $frame = $this->frameForBranch($frame, $branchTarget);
                    goto restart;
                case OpCode::TYPE_CASE:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    if ($arg1->equals($arg2)) {
                        $frame = $op->block1->getFrame($this->context, $frame);
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_CONST_FETCH:
                    $value = null;
                    if (!is_null($op->arg3)) {
                        // try NS constant fetch
                        $value = $this->context->constantFetch($frame->scope[$op->arg3]->toString());
                    }
                    if (is_null($value)) {
                        $value = $this->context->constantFetch($frame->scope[$op->arg2]->toString());
                    }
                    if (is_null($value)) {
                        return $this->raise('Unknown constant fetch', $frame);
                    }
                    $frame->scope[$op->arg1]->copyFrom($value);
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    try {
                        $className = $frame->scope[$op->arg1]->toString();
                        $methodName = $frame->scope[$op->arg2]->toString();
                        $lcClass = $this->resolveClassScopeName($className, $frame);
                        $callableName = $this->context->classes[$lcClass]->name.'::'.$methodName;
                        $this->initStaticCallable($frame, $callableName);
                    } catch (\LogicException $e) {
                        return $this->raise($e->getMessage(), $frame);
                    }
                    break;
                case OpCode::TYPE_CLASS_CONST_FETCH:
                    try {
                        $lcClass = $this->resolveClassScopeName(
                            $frame->scope[$op->arg2]->toString(),
                            $frame
                        );
                    } catch (\LogicException $e) {
                        return $this->raise($e->getMessage(), $frame);
                    }
                    $className = $frame->scope[$op->arg2]->toString();
                    if (!isset($this->context->classes[$lcClass])) {
                        if ('self' !== strtolower($className) && 'static' !== strtolower($className)) {
                            $this->context->autoloadClass($className);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        return $this->raise("Unknown class for constant fetch: {$className}", $frame);
                    }
                    $constName = strtolower($frame->scope[$op->arg3]->toString());
                    $classEntry = $this->context->classes[$lcClass];
                    if ('class' === $constName) {
                        $frame->scope[$op->arg1]->string($classEntry->name);
                        break;
                    }
                    if (!isset($classEntry->constants[$constName])) {
                        return $this->raise("Undefined class constant {$className}::{$constName}", $frame);
                    }
                    if ($classEntry->isEnum) {
                        $canonical = $classEntry->enumCaseCanonicalNames[$constName]
                            ?? $frame->scope[$op->arg3]->toString();
                        $backing = new Variable();
                        $backing->copyFrom($classEntry->constants[$constName]);
                        $frame->scope[$op->arg1]->enumCase(
                            new VM\EnumCaseEntry($classEntry, $canonical, $backing)
                        );
                        break;
                    }
                    $frame->scope[$op->arg1]->copyFrom($classEntry->constants[$constName]);
                    break;
                case OpCode::TYPE_INSTANCEOF:
                    $value = $frame->scope[$op->arg2]->resolveIndirect();
                    $className = strtolower($frame->scope[$op->arg3]->toString());
                    $matches = false;
                    if (Variable::TYPE_OBJECT === $value->type) {
                        $entry = $value->toObject()->class;
                        $target = $this->context->classes[$className] ?? null;
                        if (null !== $target && $target->isInterface) {
                            $matches = VM\InterfaceCheck::entryImplements($entry, $className, $this->context);
                        } else {
                            $matches = VM\InterfaceCheck::entryIsInstanceOf($entry, $className, $this->context);
                        }
                    }
                    $frame->scope[$op->arg1]->bool($matches);
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_FETCH:
                    $rawClass = $frame->scope[$op->arg2]->toString();
                    $lcClass = $this->resolveStaticClassName(
                        $rawClass,
                        $frame
                    );
                    if (!isset($this->context->classes[$lcClass])) {
                        if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                            $this->context->autoloadClass($rawClass);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        return $this->raise("Unknown class for static property fetch: {$rawClass}", $frame);
                    }
                    $propName = strtolower($frame->scope[$op->arg3]->toString());
                    $classEntry = $this->context->classes[$lcClass];
                    if (!isset($classEntry->staticProperties[$propName])) {
                        $classLabel = $classEntry->name;
                        return $this->raise(
                            "Undefined static property {$classLabel}::{$propName}",
                            $frame
                        );
                    }
                    $frame->scope[$op->arg1]->indirect($classEntry->staticProperties[$propName]);
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_UNSET:
                    $rawClass = $frame->scope[$op->arg2]->toString();
                    $lcClass = $this->resolveStaticClassName($rawClass, $frame);
                    if (!isset($this->context->classes[$lcClass])) {
                        if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                            $this->context->autoloadClass($rawClass);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        return $this->raise("Unknown class for static property unset: {$rawClass}", $frame);
                    }
                    $propName = strtolower($frame->scope[$op->arg3]->toString());
                    $classEntry = $this->context->classes[$lcClass];
                    if (!isset($classEntry->staticProperties[$propName])) {
                        $classLabel = $classEntry->name;

                        return $this->raise(
                            "Undefined static property {$classLabel}::{$propName}",
                            $frame
                        );
                    }
                    $storage = $classEntry->staticProperties[$propName];
                    $storage->reset();
                    $storage->type = Variable::TYPE_UNDEFINED;
                    break;
                case OpCode::TYPE_UNSET:
                    if (null === $op->arg3) {
                        if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                            $slot = $frame->scope[$op->arg2];
                            if (Variable::TYPE_INDIRECT === $slot->type) {
                                $target = $slot->resolveIndirect();
                                $target->reset();
                                $target->type = Variable::TYPE_UNDEFINED;
                            } else {
                                $slot->resolveIndirect()->null();
                            }
                        }
                        break;
                    }
                    $container = $frame->scope[$op->arg2]->resolveIndirect();
                    $key = $frame->scope[$op->arg3];
                    if (Variable::TYPE_OBJECT === $container->type) {
                        $container->toObject()->unsetProperty($key->toString());
                        break;
                    }
                    if (Variable::TYPE_ARRAY === $container->type) {
                        $container->toArray()->offsetUnset($key);
                        break;
                    }
                    break;
                case OpCode::TYPE_CLOSURE:
                    if (null === $op->block1) {
                        $frame->scope[$op->arg1]->null();
                        break;
                    }
                    $funcName = null !== $op->block1->func
                        ? $op->block1->func->name
                        : '{closure}';
                    $closureFunc = new Func\PHP($funcName, $op->block1);
                    $captures = $this->bindClosureCaptures($frame, $op->closureCaptures);
                    $state = new ClosureState($closureFunc, $captures);
                    $frame->scope[$op->arg1]->object($state->wrapObject($this->context));
                    break;
                case OpCode::TYPE_RETURN_VOID:
                    $finallyFrame = $this->beginReturnFinallyUnwind($frame, null, true);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    goto return_void_complete;
                case OpCode::TYPE_RETURN:
                    if (isset($frame->scope[$op->arg1])) {
                        $returnValue = $frame->scope[$op->arg1]->resolveIndirect();
                    } elseif (isset($frame->block->constants[$op->arg1])) {
                        $returnValue = $frame->block->constants[$op->arg1];
                    } else {
                        $returnValue = new Variable(Variable::TYPE_NULL);
                    }
                    $finallyFrame = $this->beginReturnFinallyUnwind($frame, $returnValue, false);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    goto return_value_complete;
                case OpCode::TYPE_FUNCDEF:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->functions[$lcname])) {
                        throw new \LogicException("Duplicate function definition for $lcname()");
                    }
                    $this->context->declareFunction(new Func\PHP($name, $op->block1));
                    break;
                case OpCode::TYPE_FUNCCALL_INIT:
                    $callee = $frame->scope[$op->arg1]->resolveIndirect();
                    if (Variable::TYPE_OBJECT === $callee->type) {
                        $closureState = $callee->toObject()->closureState;
                        if (null !== $closureState) {
                            $frame->call = $closureState->func;
                            $frame->closureCall = $closureState;
                            $frame->callArgs = [];
                            $frame->callArgEntries = [];
                            break;
                        }
                        $this->initMethodCall($frame, $callee, '__invoke');
                        break;
                    }
                    if (Variable::TYPE_ARRAY === $callee->type) {
                        $this->initArrayCallable($frame, $callee);
                        break;
                    }
                    $name = $callee->toString();
                    if (str_contains($name, '::')) {
                        $this->initStaticCallable($frame, $name);
                        break;
                    }
                    $lcname = strtolower($name);
                    if (!isset($this->context->functions[$lcname])) {
                        throw new \LogicException("Call to undefined function $lcname()");
                    }
                    $frame->call = $this->context->functions[$lcname];
                    $frame->callArgs = [];
                    $frame->callArgEntries = [];
                    break;
                case OpCode::TYPE_METHODCALL_INIT:
                    $receiver = $frame->scope[$op->arg1]->resolveIndirect();
                    if ($receiver->type !== Variable::TYPE_OBJECT) {
                        throw new \LogicException('Method call on non-object');
                    }
                    $this->initMethodCall(
                        $frame,
                        $receiver,
                        $frame->scope[$op->arg2]->toString()
                    );
                    break;
                case OpCode::TYPE_ARG_SEND:
                    $value = $frame->scope[$op->arg1];
                    if (null !== $op->arg3) {
                        $spread = $value->resolveIndirect();
                        if (Variable::TYPE_ARRAY !== $spread->type) {
                            throw new \LogicException('Only arrays can be unpacked');
                        }
                        foreach ($spread->toArray()->iterate(true) as $element) {
                            $frame->callArgEntries[] = ['p', $element];
                        }
                        break;
                    }
                    if (null !== $op->arg2 && isset($frame->block->constants[$op->arg2])) {
                        $frame->callArgEntries[] = [
                            'n',
                            $frame->block->constants[$op->arg2]->toString(),
                            $value,
                        ];
                    } else {
                        $frame->callArgEntries[] = ['p', $value];
                    }
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    if (is_null($frame->call)) {
                        // Used for null constructors, etc
                        $this->markPendingNewObjectConstructed($frame);
                        break;
                    }
                    if ($frame->call instanceof Func\PHP && $frame->call->block->isGenerator) {
                        try {
                            $calledArgs = $this->resolveOutgoingCallArgs($frame);
                        } catch (\LogicException $e) {
                            return $this->raise($e->getMessage(), $frame);
                        }
                        $state = new GeneratorState($this, $frame->call, $calledArgs);
                        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                            $this->scopeSlot($frame, (int) $op->arg1)->object($state->wrapObject());
                        }
                        $frame->call = null;
                        $frame->callArgs = [];
                        $frame->callArgEntries = [];
                        break;
                    }
                    $new = $frame->call->getFrame($this->context, $frame);
                    $this->bindClosureCallCaptures($new, $frame->closureCall);
                    $frame->closureCall = null;
                    $new->calledClass = $this->inferCalledClass($frame);
                    $new->returnVar = null;
                    if ($op->type === OpCode::TYPE_FUNCCALL_EXEC_RETURN) {
                        $new->returnVar = $this->scopeSlot($frame, (int) $op->arg1);
                    } else {
                        $new->returnVar = null;
                    }
                    try {
                        $new->calledArgs = $this->resolveOutgoingCallArgs($frame);
                    } catch (\LogicException $e) {
                        return $this->raise($e->getMessage(), $frame);
                    }
                    if ($new->hasHandler()) {
                        $new->parent = $frame;
                        $new->vmContext = $this->context;
                        $new->handler->execute($new);
                        break;
                    }
                    $this->context->push($frame);
                    $frame = $new;
                    goto restart;
                case OpCode::TYPE_ARG_RECV:
                    $arg1 = $frame->scope[$op->arg1];
                    $recvIdx = $op->arg2;
                    if (
                        null !== $frame->block->func
                        && null !== $frame->block->func->class
                        && !(($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
                    ) {
                        ++$recvIdx;
                    }
                    $isVariadicSlot = null !== $frame->block->variadicParamIndex
                        && $frame->block->variadicParamIndex === (int) $op->arg2;
                    if ($isVariadicSlot) {
                        $arg1->newArray();
                        $packed = $arg1->toArray();
                        $n = count($frame->calledArgs);
                        for ($i = $recvIdx; $i < $n; ++$i) {
                            $copy = new Variable();
                            $copy->copyFrom($frame->calledArgs[$i]);
                            $packed->append($copy);
                        }
                    } elseif (array_key_exists($recvIdx, $frame->calledArgs)) {
                        if (isset($frame->block->paramByRef[(int) $op->arg2])) {
                            $arg1->indirect($frame->calledArgs[$recvIdx]);
                        } else {
                            $arg1->copyFrom($frame->calledArgs[$recvIdx]);
                        }
                    } elseif (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                        $arg1->copyFrom($frame->block->constants[$op->arg3]);
                    } else {
                        throw new \LogicException('Missing required argument ' . $op->arg2);
                    }
                    $strict = null !== $frame->parent
                        ? $frame->parent->block->strictTypes
                        : $frame->block->strictTypes;
                    TypeCheck::coerceParameter($arg1, $strict);
                    if (isset($frame->block->paramIntersectionConstraints[$op->arg1])) {
                        TypeCheck::assertParamIntersection(
                            $arg1,
                            $frame->block->paramIntersectionConstraints[$op->arg1],
                            $this->context
                        );
                    }
                    break;
                case OpCode::TYPE_DECLARE_INTERFACE:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate interface definition for $name");
                    }
                    $ifaceEntry = new VM\ClassEntry($name);
                    $ifaceEntry->isInterface = true;
                    $ifaceEntry->interfaces = $op->classImplements;
                    $this->context->classes[$lcname] = $ifaceEntry;
                    break;
                case OpCode::TYPE_DECLARE_TRAIT:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate trait definition for $name");
                    }
                    $traitEntry = new ClassEntry($name);
                    $traitEntry->isTrait = true;
                    $traitEntry->attributeNames = $op->attributeNames;
                    self::defineClass($traitEntry, $op->block1);
                    $this->context->classes[$lcname] = $traitEntry;
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL_CONST:
                    $name = $frame->scope[$op->arg1]->toString();
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Global constant value must be a compile-time constant');
                    }
                    if (!$this->context->defineConstant($name, $frame->block->constants[$op->arg2])) {
                        throw new \LogicException("Cannot redefine constant {$name}");
                    }
                    break;
                case OpCode::TYPE_DECLARE_ENUM:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname]) || isset($this->context->enums[$lcname])) {
                        throw new \LogicException("Duplicate enum definition for $name");
                    }
                    $classEntry = new ClassEntry($name);
                    $classEntry->isEnum = true;
                    if (null !== $op->arg2 && isset($frame->block->constants[$op->arg2])) {
                        $classEntry->backedType = $frame->block->constants[$op->arg2]->toString();
                    }
                    $classEntry->interfaces = $op->classImplements;
                    self::defineClass($classEntry, $op->block1);
                    $this->context->classes[$lcname] = $classEntry;
                    $this->context->enums[$lcname] = true;
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate class definition for $name");
                    }
                    $classEntry = new ClassEntry($name);
                    $classEntry->interfaces = $op->classImplements;
                    if (null !== $op->arg2) {
                        $parentName = $frame->scope[$op->arg2]->toString();
                        $parentLc = strtolower($parentName);
                        if (!isset($this->context->classes[$parentLc])) {
                            $this->context->autoloadClass($parentName);
                        }
                        if (!isset($this->context->classes[$parentLc])) {
                            throw new \LogicException("Class {$name} extends unknown class {$parentName}");
                        }
                        $classEntry->parentLc = $parentLc;
                    }
                    if (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                        $classEntry->readonly = (bool) $frame->block->constants[$op->arg3]->toInt();
                    }
                    $classEntry->attributeNames = $op->attributeNames;
                    self::defineClass($classEntry, $op->block1);
                    if (null !== $classEntry->parentLc) {
                        $this->inheritFromParent($classEntry);
                    }
                    $this->context->classes[$lcname] = $classEntry;
                    break;
                case OpCode::TYPE_NEW:
                    $result = $frame->scope[$op->arg1];
                    $name = $frame->scope[$op->arg2]->toString();
                    $lcname = strtolower($name);
                    if (!isset($this->context->classes[$lcname])) {
                        $this->context->autoloadClass($name);
                    }
                    if (!isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Attempting to instantiate non-existing class $name");
                    }
                    $class = $this->context->classes[$lcname];
                    $object = new ObjectEntry($class);
                    $result->object($object);
                    $frame->call = $object->constructor;
                    $frame->callArgs = [$result];
                    $frame->callArgEntries = [];
                    if (null === $frame->call) {
                        $object->constructed = true;
                    }
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                    $result = $frame->scope[$op->arg1];
                    $var = $frame->scope[$op->arg2]->resolveIndirect();
                    $name = $frame->scope[$op->arg3]->toString();
                    if (Variable::TYPE_ENUM_CASE === $var->type) {
                        try {
                            $prop = $var->toEnumCase()->fetchProperty($name);
                        } catch (\LogicException $e) {
                            return $this->raise($e->getMessage(), $frame);
                        }
                        $result->copyFrom($prop);
                        break;
                    }
                    if ($var->type !== Variable::TYPE_OBJECT) {
                        throw new \LogicException("Unsupported property fetch on non-object");
                    }
                    $result->indirect($var->toObject()->getProperty($name));
                    break;
                case OpCode::TYPE_INIT_ARRAY:
                    $result = $frame->scope[$op->arg1];
                    $result->newArray();
                    if (is_null($op->arg2)) {
                        break;
                    }
                    // Fall through intentional
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                    $result = $frame->scope[$op->arg1];
                    $ht = $result->toArray();
                    if (is_null($op->arg3)) {
                        $ht->append($frame->scope[$op->arg2]);
                        break;
                    }
                    $key = $frame->scope[$op->arg3]->resolveIndirect();
                    if ($key->is(Variable::TYPE_INTEGER)) {
                        $ht->addIndex($key->toInt(), $frame->scope[$op->arg2]);
                    } else {
                        $ht->add($key->toString(), $frame->scope[$op->arg2]);
                    }
                    break;
                case OpCode::TYPE_ARRAY_SPREAD:
                    $result = $frame->scope[$op->arg1];
                    $source = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_ARRAY !== $source->type) {
                        throw new \LogicException(
                            Variable::TYPE_NULL === $source->type
                                ? 'Cannot spread null'
                                : 'Only arrays can be spread'
                        );
                    }
                    $result->toArray()->spreadFrom($source->toArray());
                    break;
                case OpCode::TYPE_CLONE:
                    $result = $frame->scope[$op->arg1];
                    $src = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_OBJECT !== $src->type) {
                        throw new \LogicException('clone requires an object');
                    }
                    $result->object($src->toObject()->cloneShallow());
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                    $value = !($frame->scope[$op->arg2]->toBool());
                    $dst = $frame->scope[$op->arg1];
                    $dst->bool($value);
                    break;
                case OpCode::TYPE_EMPTY:
                    $v = $frame->scope[$op->arg2]->resolveIndirect();
                    $frame->scope[$op->arg1]->bool(!ext\standard\boolval::isTruthy($v));
                    break;
                case OpCode::TYPE_ISSET:
                    $dst = $frame->scope[$op->arg1];
                    if (null !== $op->arg3) {
                        $container = $frame->scope[$op->arg2]->resolveIndirect();
                        if (Variable::TYPE_ARRAY !== $container->type) {
                            $dst->bool(false);
                            break;
                        }
                        $dst->bool($container->toArray()->offsetIsSet($frame->scope[$op->arg3]));
                        break;
                    }
                    $value = $frame->scope[$op->arg2]->resolveIndirect();
                    $dst->bool(
                        !$value->isUndefined()
                        && Variable::TYPE_NULL !== $value->type
                    );
                    break;
                case OpCode::TYPE_SCRIPT_MAGIC:
                    $dst = $frame->scope[$op->arg1];
                    if (OpCode::SCRIPT_MAGIC_LINE === $op->arg3) {
                        $line = null !== $op->arg2 ? (int) $op->arg2 : 0;
                        if ($line < 1) {
                            $line = 1;
                        }
                        $dst->int($line);
                        break;
                    }
                    $script = '' !== $frame->scriptPath
                        ? $frame->scriptPath
                        : $this->context->scriptStack->current();
                    if ('' === $script) {
                        return $this->raise('__DIR__/__FILE__ used without script context', $frame);
                    }
                    if (OpCode::SCRIPT_MAGIC_DIR === $op->arg3) {
                        $dst->string(dirname($script));
                    } else {
                        $dst->string($script);
                    }
                    break;
                case OpCode::TYPE_INCLUDE:
                    $file = null;
                    if (null !== $op->arg3 && isset($frame->block->literalIncludePaths[$op->arg3])) {
                        $file = $frame->block->literalIncludePaths[$op->arg3];
                    } elseif (null !== $op->arg3 && isset($frame->block->deployIncludePaths[$op->arg3])) {
                        $spec = $frame->block->deployIncludePaths[$op->arg3];
                        $file = $spec['compile'] ?? \PHPCompiler\Web\DeployRoot::resolvePathWithSuffix(
                            $spec['rel'],
                            $spec['fallback'],
                            $spec['suffix']
                        );
                    }
                    if (null === $file) {
                        $file = $frame->scope[$op->arg1]->toString();
                    }
                    $resolved = VM\ScriptStack::normalize($file);
                    if ('' === $resolved || !is_file($resolved)) {
                        return $this->raise('Failed opening required \''.$file.'\' for inclusion', $frame);
                    }
                    $this->context->markCompileUnitLoaded($resolved);
                    $this->context->scriptStack->push($resolved);
                    $parsed = $this->context->runtime->parseAndCompileFile($resolved);
                    $new = $parsed->getFrame($this->context, $frame);
                    $new->ephemeral = true;
                    $new->parent = $frame;
                    if (null !== $op->arg2) {
                        $new->returnVar = $frame->scope[$op->arg2];
                        $new->returnVar->int(1);
                    }
                    $frame = $new;
                    goto restart;
                case OpCode::TYPE_YIELD:
                    $gen = $this->findGeneratorState($frame);
                    if (null === $gen) {
                        throw new \LogicException('yield outside generator function');
                    }
                    if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                        $gen->currentValue->copyFrom($frame->scope[$op->arg2]->resolveIndirect());
                    } else {
                        $gen->currentValue->null();
                    }
                    if (null !== $op->arg3 && isset($frame->scope[$op->arg3])) {
                        $gen->currentKey->copyFrom($frame->scope[$op->arg3]->resolveIndirect());
                    } else {
                        $gen->currentKey->int($gen->autoKey++);
                    }
                    $gen->hasCurrent = true;
                    $gen->frame = $frame;
                    $frame->generatorYield = true;
                    break;
                case OpCode::TYPE_YIELD_FROM:
                    $gen = $this->findGeneratorState($frame);
                    if (null === $gen) {
                        throw new \LogicException('yield from outside generator function');
                    }
                    if (null === $op->arg2 || !isset($frame->scope[$op->arg2])) {
                        throw new \LogicException('yield from missing container operand');
                    }
                    if (!$gen->yieldFromActive) {
                        $container = $frame->scope[$op->arg2]->resolveIndirect();
                        $gen->yieldFromContainer->copyFrom($container);
                        $gen->yieldFromActive = true;
                        if (Variable::TYPE_ARRAY === $container->type) {
                            $container->toArray()->iterReset();
                        } elseif ($this->variableIsGenerator($container)) {
                            $container->toObject()->generatorState->rewind();
                        }
                    }
                    $container = $gen->yieldFromContainer->resolveIndirect();
                    if (Variable::TYPE_ARRAY === $container->type) {
                        if ($container->toArray()->iterValid()) {
                            $gen->currentKey->copyFrom($container->toArray()->iterCurrentKey());
                            $gen->currentValue->copyFrom($container->toArray()->iterCurrentValue(false));
                            $gen->hasCurrent = true;
                            $gen->frame = $frame;
                            $frame->pos--;
                            $frame->generatorYield = true;
                            break;
                        }
                        $gen->yieldFromActive = false;
                        break;
                    }
                    if ($this->variableIsGenerator($container)) {
                        $inner = $container->toObject()->generatorState;
                        if ($this->advanceGeneratorIteration($inner)) {
                            $gen->currentKey->copyFrom($inner->currentKey);
                            $gen->currentValue->copyFrom($inner->currentValue);
                            $gen->hasCurrent = true;
                            $gen->frame = $frame;
                            $frame->pos--;
                            $frame->generatorYield = true;
                            break;
                        }
                        $gen->yieldFromActive = false;
                        break;
                    }
                    throw new \LogicException('yield from requires array or Generator');
                case OpCode::TYPE_ITER_RESET:
                    $container = $frame->scope[$op->arg1]->resolveIndirect();
                    $frame->iterators[$op->arg1] = $container;
                    $this->context->foreachIterators[$op->arg1] = $container;
                    if ($this->variableIsGenerator($container)) {
                        $container->toObject()->generatorState->rewind();
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator reset requires an array');
                    }
                    $container->toArray()->iterReset();
                    break;
                case OpCode::TYPE_ITER_VALID:
                    $container = ($this->context->foreachIterators[$op->arg2] ?? ($frame->iterators[$op->arg2] ?? $frame->scope[$op->arg2]))->resolveIndirect();
                    if ($this->variableIsGenerator($container)) {
                        $frame->scope[$op->arg1]->bool(
                            $this->advanceGeneratorIteration($container->toObject()->generatorState)
                        );
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator valid requires an array');
                    }
                    $frame->scope[$op->arg1]->bool($container->toArray()->iterValid());
                    break;
                case OpCode::TYPE_ITER_KEY:
                    $container = ($this->context->foreachIterators[$op->arg2] ?? ($frame->iterators[$op->arg2] ?? $frame->scope[$op->arg2]))->resolveIndirect();
                    if ($this->variableIsGenerator($container)) {
                        $frame->scope[$op->arg1]->copyFrom(
                            $container->toObject()->generatorState->currentKey
                        );
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator key requires an array');
                    }
                    $frame->scope[$op->arg1]->copyFrom($container->toArray()->iterCurrentKey());
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    $container = ($this->context->foreachIterators[$op->arg2] ?? ($frame->iterators[$op->arg2] ?? $frame->scope[$op->arg2]))->resolveIndirect();
                    if ($this->variableIsGenerator($container)) {
                        $frame->scope[$op->arg1]->copyFrom(
                            $container->toObject()->generatorState->currentValue
                        );
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator value requires an array');
                    }
                    $byRef = (bool) $op->arg3;
                    if ($byRef) {
                        $frame->scope[$op->arg1]->indirect(
                            $container->toArray()->iterCurrentValue(true)
                        );
                    } else {
                        $frame->scope[$op->arg1]->copyFrom(
                            $container->toArray()->iterCurrentValue(false)
                        );
                    }
                    break;
                case OpCode::TYPE_TRY:
                    $frame = $op->block1->getFrame($this->context, $frame);
                    goto restart;
                case OpCode::TYPE_CATCH:
                    if (null !== $this->context->pendingException) {
                        if ($this->catchTypesMatch($op, $this->context->pendingException)) {
                            $caught = $this->context->pendingException;
                            $this->context->pendingException = null;
                            $frame = $op->block1->getFrame($this->context, $frame);
                            if (null !== $op->arg3) {
                                $frame->scope[$op->arg3]->copyFrom($caught);
                            }
                            goto restart;
                        }
                        break;
                    }
                    if (null !== $op->block2) {
                        $frame = $op->block2->getFrame($this->context, $frame);
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_FINALLY:
                    if (null !== $this->context->pendingException) {
                        break;
                    }
                    if (null !== $op->block1) {
                        $frame = $op->block1->getFrame($this->context, $frame);
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_THROW:
                    $thrown = $frame->scope[$op->arg1]->resolveIndirect();
                    $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $this->raiseUncaughtException($thrown);
                    break;
                default:
                    throw new \LogicException("VM OpCode Not Implemented: " . opcode_type_name($op->type));
            }
            if ($frame->generatorYield) {
                $frame->generatorYield = false;

                return self::GENERATOR_YIELD;
            }
        }
        if ($frame->ephemeral) {
            $this->context->scriptStack->pop();
            if (null !== $frame->parent) {
                $this->markObjectConstructedIfLeavingConstruct($frame);
                $frame = $frame->parent;
                goto restart;
            }
            goto nextframe;
        }

        return self::SUCCESS;

        return_void_complete:
        $this->enforceReturnType($frame, null);
        // Do not null returnVar: it may alias the caller result slot (#1885).
        $this->markObjectConstructedIfLeavingConstruct($frame);
        $gen = $this->findGeneratorState($frame);
        if (null !== $gen) {
            $gen->done = true;
            $gen->frame = null;
            $gen->hasCurrent = false;
            goto nextframe;
        }
        if ($frame->ephemeral && null !== $frame->parent) {
            $frame = $frame->parent;
            goto restart;
        }
        goto nextframe;

        return_value_complete:
        $this->enforceReturnType($frame, $returnValue);
        if (!is_null($frame->returnVar)) {
            $frame->returnVar->copyFrom($returnValue);
        }
        $this->markObjectConstructedIfLeavingConstruct($frame);
        $caller = $this->context->pop();
        if (null !== $caller) {
            $frame = $caller;
            goto restart;
        }
        // Nested return <call>(): callee may finish with an empty run stack (#1885).
        if (null !== $frame->parent && null !== $frame->returnVar) {
            $frame = $frame->parent;
            goto restart;
        }
        if ($frame->ephemeral && null !== $frame->parent) {
            $frame = $frame->parent;
            goto restart;
        }

        return self::SUCCESS;
    }

    /**
     * Goto / label back-edges reuse the innermost frame for the target block (#1228).
     * php-cfg lowers `if (cond) goto L` as JumpIf to the label block; naive getFrame()
     * nests a new frame per iteration and never terminates on merge blocks.
     */
    private function frameForBranch(Frame $frame, Block $target): Frame
    {
        if ($target === $frame->block) {
            while (null !== $frame->parent && $frame->parent->block === $target) {
                $frame = $frame->parent;
            }
            $frame->pos = 0;

            return $frame;
        }

        return $target->getFrame($this->context, $frame);
    }

    protected function raise(string $message, Frame $frame): int
    {
        $where = '' !== $frame->scriptPath ? $frame->scriptPath : 'script';
        throw new \LogicException($message.' in '.$where);
    }

    private function findCatchFrameForThrow(Frame $frame, Variable $thrown): ?Frame
    {
        $this->context->pendingException = $thrown;
        for ($handler = $frame->parent ?? $frame; null !== $handler; $handler = $handler->parent) {
            $this->rewindHandlerToCatchChain($handler);
            $finallyFrame = $this->enterFinallyHandlerForUnwind($handler);
            if (null !== $finallyFrame) {
                return $finallyFrame;
            }
            $catchFrame = $this->enterMatchingCatchHandler($handler);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $this->clearTryCatchUnwindState();

        return null;
    }

    /** Align handler position to the first TYPE_CATCH after TYPE_TRY (issue #1362). */
    private function rewindHandlerToCatchChain(Frame $handler): void
    {
        $ops = $handler->block->opCodes;
        $n = $handler->block->nOpCodes;
        for ($i = 0; $i < $n; ++$i) {
            if (OpCode::TYPE_TRY !== $ops[$i]->type) {
                continue;
            }
            for ($j = $i + 1; $j < $n; ++$j) {
                if (OpCode::TYPE_CATCH === $ops[$j]->type) {
                    $handler->pos = $j;

                    return;
                }
                if (OpCode::TYPE_FINALLY === $ops[$j]->type) {
                    return;
                }
            }

            return;
        }
    }

    private function enterMatchingCatchHandler(Frame $handler): ?Frame
    {
        if (null === $this->context->pendingException) {
            return null;
        }
        while ($handler->pos < $handler->block->nOpCodes) {
            $op = $handler->block->opCodes[$handler->pos];
            if (OpCode::TYPE_CATCH !== $op->type) {
                if (OpCode::TYPE_FINALLY === $op->type) {
                    break;
                }

                return null;
            }
            $handler->pos++;
            if (!$this->catchTypesMatch($op, $this->context->pendingException)) {
                continue;
            }
            $caught = $this->context->pendingException;
            $this->context->pendingException = null;
            $catchFrame = $op->block1->getFrame($this->context, $handler);
            if (null !== $op->arg3) {
                $catchFrame->scope[$op->arg3]->copyFrom($caught);
            }
            $mergeFrame = null;
            if (null !== $op->block2) {
                $mergeFrame = $op->block2->getFrame($this->context, $handler);
                $mergeFrame->parent = $handler->parent;
            }
            $this->skipTryCatchHandlerTail($handler);
            if (null !== $mergeFrame) {
                $handler->pos = $handler->block->nOpCodes;
                $catchFrame->parent = $mergeFrame;
            }
            $this->clearTryCatchUnwindState();

            return $catchFrame;
        }

        return null;
    }

    private function enterFinallyHandlerForUnwind(Frame $handler): ?Frame
    {
        $handlerId = spl_object_id($handler);
        if (isset($this->context->completedFinallyHandlers[$handlerId])) {
            return null;
        }
        $finallyOp = $this->findFinallyOpForHandler($handler);
        if (null === $finallyOp || null === $finallyOp->block1) {
            return null;
        }
        $this->context->completedFinallyHandlers[$handlerId] = true;
        $this->context->pendingCatchResumeHandler = $handler;

        return $finallyOp->block1->getFrame($this->context, $handler);
    }

    private function findFinallyOpForHandler(Frame $handler): ?OpCode
    {
        foreach ($handler->block->opCodes as $op) {
            if (OpCode::TYPE_FINALLY === $op->type) {
                return $op;
            }
        }

        return null;
    }

    private function resumeCatchAfterFinally(Frame $frame): ?Frame
    {
        $handler = $this->context->pendingCatchResumeHandler;
        if (null === $handler) {
            return null;
        }
        $this->context->pendingCatchResumeHandler = null;
        $this->rewindHandlerToCatchChain($handler);
        $catchFrame = $this->enterMatchingCatchHandler($handler);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $thrown = $this->context->pendingException;
        if (null === $thrown) {
            return null;
        }
        $outerCatch = $this->findCatchFrameForThrow($handler->parent ?? $handler, $thrown);
        if (null !== $outerCatch) {
            return $outerCatch;
        }
        $this->raiseUncaughtException($thrown);
    }

    private function clearTryCatchUnwindState(): void
    {
        $this->context->pendingException = null;
        $this->context->pendingCatchResumeHandler = null;
        $this->context->completedFinallyHandlers = [];
        $this->clearPendingReturnState();
    }

    private function clearPendingReturnState(): void
    {
        $this->context->pendingReturnActive = false;
        $this->context->pendingReturnDispatch = false;
        $this->context->pendingReturnIsVoid = true;
        $this->context->pendingReturnValue = null;
        $this->context->pendingReturnResumeFrame = null;
    }

    private function hasPendingFinally(Frame $handler): bool
    {
        if (null === $this->findFinallyOpForHandler($handler)) {
            return false;
        }

        return !isset($this->context->completedFinallyHandlers[spl_object_id($handler)]);
    }

    /** Normal try completion runs the finally CFG block directly; mark it done (#3082). */
    private function markFinallyCompletedWhenLeavingFinallyBody(Frame $frame): void
    {
        for ($handler = $frame->parent; null !== $handler; $handler = $handler->parent) {
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if ($finallyOp->block1 !== $frame->block) {
                continue;
            }
            $this->context->completedFinallyHandlers[spl_object_id($handler)] = true;

            return;
        }
    }

    private function findNextFinallyHandlerForReturn(Frame $from): ?Frame
    {
        for ($handler = $from->parent; null !== $handler; $handler = $handler->parent) {
            if ($this->hasPendingFinally($handler)) {
                return $handler;
            }
        }

        return null;
    }

    private function beginReturnFinallyUnwind(Frame $frame, ?Variable $value, bool $isVoid): ?Frame
    {
        $handler = $this->findNextFinallyHandlerForReturn($frame);
        if (null === $handler) {
            return null;
        }
        $this->context->pendingReturnActive = true;
        $this->context->pendingReturnIsVoid = $isVoid;
        $this->context->pendingReturnValue = $value;
        $this->context->pendingReturnResumeFrame = $frame;

        return $this->enterFinallyHandlerForUnwind($handler);
    }

    private function continueReturnFinallyChain(): ?Frame
    {
        if (!$this->context->pendingReturnActive || null === $this->context->pendingReturnResumeFrame) {
            return null;
        }
        $handler = $this->findNextFinallyHandlerForReturn($this->context->pendingReturnResumeFrame);
        if (null === $handler) {
            return null;
        }

        return $this->enterFinallyHandlerForUnwind($handler);
    }

    private function schedulePendingReturnDispatch(): bool
    {
        if (!$this->context->pendingReturnActive || null === $this->context->pendingReturnResumeFrame) {
            return false;
        }
        if (null !== $this->findNextFinallyHandlerForReturn($this->context->pendingReturnResumeFrame)) {
            return false;
        }
        $this->context->pendingReturnDispatch = true;

        return true;
    }

    /** @return never */
    private function raiseUncaughtException(Variable $thrown): void
    {
        $this->clearTryCatchUnwindState();
        if (Variable::TYPE_OBJECT === $thrown->type) {
            $entry = $thrown->toObject();
            try {
                $message = $entry->getProperty('message')->toString();
            } catch (\LogicException) {
                $message = 'Exception';
            }
            throw new \Exception($message);
        }
        throw new \Exception($thrown->toString());
    }

    /**
     * After a catch match, skip remaining TYPE_CATCH / CFG entry TYPE_JUMP on the handler
     * block so merge fallthrough does not re-enter the try body (#2084).
     */
    private function skipTryCatchHandlerTail(Frame $handler): void
    {
        while ($handler->pos < $handler->block->nOpCodes) {
            $op = $handler->block->opCodes[$handler->pos];
            if (OpCode::TYPE_CATCH === $op->type || OpCode::TYPE_FINALLY === $op->type) {
                $handler->pos++;
                continue;
            }
            if (OpCode::TYPE_JUMP === $op->type) {
                $handler->pos++;
                continue;
            }
            break;
        }
    }

    private function catchTypesMatch(OpCode $op, Variable $thrown): bool
    {
        $encoded = $op->catchTypes;
        if (null === $encoded || '' === $encoded) {
            return true;
        }
        $types = explode('|', $encoded);
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            return false;
        }
        $class = $thrown->toObject()->class;
        foreach ($types as $typeName) {
            if ('' === $typeName) {
                continue;
            }
            if ($this->objectIsInstanceOfClass($class, $typeName)) {
                return true;
            }
        }

        return false;
    }

    private function objectIsInstanceOfClass(ClassEntry $class, string $typeName): bool
    {
        $want = strtolower(ltrim($typeName, '\\'));
        $current = $class;
        while (true) {
            if (strtolower($current->name) === $want) {
                return true;
            }
            if (null === $current->parentLc || !isset($this->context->classes[$current->parentLc])) {
                return false;
            }
            $current = $this->context->classes[$current->parentLc];
        }
    }

    /** Reject readonly property writes; returns a failure exit code or null. */
    private function enforceReadonlyPropertyWrite(Variable $lvalue, Frame $frame): ?int
    {
        $target = $lvalue->resolveIndirect();
        $owner = $target->objectPropertyOwner;
        if (null === $owner || !$owner->class->readonly || !$owner->constructed) {
            return null;
        }
        $prop = $target->objectPropertyName ?? 'property';

        return $this->raise(
            sprintf('Cannot modify readonly property %s::$%s', $owner->class->name, $prop),
            $frame
        );
    }

    private function markObjectConstructedIfLeavingConstruct(Frame $frame): void
    {
        if (!$this->isConstructFrame($frame)) {
            return;
        }
        if (empty($frame->calledArgs)) {
            return;
        }
        $thisArg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thisArg->type) {
            return;
        }
        $thisArg->toObject()->constructed = true;
    }

    private function markPendingNewObjectConstructed(Frame $frame): void
    {
        if (empty($frame->callArgs)) {
            return;
        }
        $objVar = $frame->callArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objVar->type) {
            return;
        }
        $objVar->toObject()->constructed = true;
    }

    private function isConstructFrame(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
    }

    private function variableIsGenerator(Variable $container): bool
    {
        $container = $container->resolveIndirect();

        return Variable::TYPE_OBJECT === $container->type
            && null !== $container->toObject()->generatorState;
    }

    private function findGeneratorState(Frame $frame): ?GeneratorState
    {
        while (null !== $frame) {
            if (null !== $frame->generatorState) {
                return $frame->generatorState;
            }
            $frame = $frame->parent;
        }

        return null;
    }

    private function advanceGeneratorIteration(GeneratorState $gen): bool
    {
        if ($gen->done) {
            return false;
        }
        if (null === $gen->frame) {
            $gen->frame = $gen->func->getFrame($this->context, null);
            $gen->frame->calledArgs = $gen->calledArgs;
            $gen->frame->generatorState = $gen;
            $gen->frame->pos = 0;
        }
        $savedStack = $this->context->swapRunStack(null);
        try {
            $this->context->push($gen->frame);
            $result = $this->runFrames();
        } finally {
            $this->context->swapRunStack($savedStack);
        }
        if (self::GENERATOR_YIELD === $result) {
            return $gen->hasCurrent;
        }
        $gen->frame = null;
        if (self::SUCCESS === $result) {
            $gen->done = true;
        }

        return false;
    }

    /**
     * @return list<Variable>
     */
    private function resolveOutgoingCallArgs(Frame $frame): array
    {
        if (null === $frame->call) {
            return $frame->callArgs;
        }

        [$paramNames, $variadicIndex] = $this->calleeParamMetadata($frame->call);
        $userArgs = [] === $frame->callArgEntries
            ? []
            : NamedArgs::resolve($frame->callArgEntries, $paramNames, $variadicIndex);

        if ([] === $frame->callArgs) {
            return $userArgs;
        }

        return array_merge($frame->callArgs, $userArgs);
    }

    /**
     * @return array{0: list<string>, 1: ?int}
     */
    private function calleeParamMetadata(Func $call): array
    {
        if ($call instanceof Func\PHP) {
            return [$call->block->paramNames, $call->block->variadicParamIndex];
        }
        if ($call instanceof Func\Internal) {
            return [BuiltinParamNames::forFunction($call->getName()) ?? [], null];
        }

        return [[], null];
    }

    protected function scopeSlot(Frame $frame, int $slot): Variable
    {
        if (!isset($frame->scope[$slot])) {
            $frame->scope[$slot] = new Variable();
        }

        return $frame->scope[$slot];
    }

    /**
     * @param list<array{name: string, slot: int, byRef: bool}> $captureSpecs
     *
     * @return list<array{slot: int, var: Variable, byRef: bool}>
     */
    protected function bindClosureCaptures(Frame $frame, array $captureSpecs): array
    {
        $captures = [];
        foreach ($captureSpecs as $spec) {
            $src = Block::findVariableInParentFramesByName($spec['name'], $frame);
            $stored = new Variable();
            if (null === $src) {
                $stored->null();
            } elseif ($spec['byRef']) {
                $stored->indirect($src->resolveIndirect());
            } else {
                $stored->copyFrom($src->resolveIndirect());
            }
            $captures[] = [
                'slot' => $spec['slot'],
                'var' => $stored,
                'byRef' => $spec['byRef'],
            ];
        }

        return $captures;
    }

    protected function bindClosureCallCaptures(Frame $callee, ?ClosureState $closureState): void
    {
        if (null === $closureState || [] === $closureState->captures) {
            return;
        }
        foreach ($closureState->captures as $capture) {
            $dest = $this->scopeSlot($callee, $capture['slot']);
            if ($capture['byRef']) {
                $dest->indirect($capture['var']->resolveIndirect());
            } else {
                $dest->copyFrom($capture['var']);
            }
        }
    }

    protected function resolveStaticClassName(string $className, Frame $frame): string
    {
        return $this->resolveClassScopeName($className, $frame);
    }

    protected function resolveClassScopeName(string $className, Frame $frame): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass) {
            return $this->declaringClassLc($frame);
        }
        if ('static' === $lcClass) {
            return $this->lateStaticClassLc($frame);
        }
        if ('parent' === $lcClass) {
            $declaring = $this->declaringClassLc($frame);
            if (!isset($this->context->classes[$declaring])) {
                throw new \LogicException('parent:: used outside of class scope');
            }
            $parentLc = $this->context->classes[$declaring]->parentLc;
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parentLc;
        }

        return $lcClass;
    }

    protected function declaringClassLc(Frame $frame): string
    {
        if (null === $frame->block->func || null === $frame->block->func->class) {
            throw new \LogicException('self:: used outside of class scope');
        }

        return strtolower($frame->block->func->class->value);
    }

    protected function lateStaticClassLc(Frame $frame): string
    {
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return strtolower($frame->calledClass);
        }

        return $this->declaringClassLc($frame);
    }

    protected function inferCalledClass(Frame $frame): ?string
    {
        if (null !== $frame->staticCallClass) {
            $called = $frame->staticCallClass;
            $frame->staticCallClass = null;

            return $called;
        }
        if (!empty($frame->callArgs)) {
            $receiver = $frame->callArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $receiver->type) {
                return $receiver->toObject()->class->name;
            }
        }

        return $frame->calledClass;
    }

    protected function initMethodCall(Frame $frame, Variable $receiver, string $methodName): void
    {
        $methodLc = strtolower($methodName);
        $object = $receiver->toObject();
        if (null !== $object->closureState && '__invoke' === $methodLc) {
            $frame->call = $object->closureState->func;
            $frame->closureCall = $object->closureState;
            $frame->callArgs = [];
            $frame->callArgEntries = [];

            return;
        }
        $class = $object->class;
        if (!isset($class->methods[$methodLc])) {
            throw new \LogicException("Call to undefined method {$class->name}::{$methodLc}()");
        }
        $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = null;
        if (null !== $frame->block->func && null !== $frame->block->func->class) {
            $callerClassLc = strtolower($frame->block->func->class->value);
        }
        MethodVisibility::assertCallable(
            $vis,
            $callerClassLc,
            strtolower($class->name),
            $class->name,
            $methodName
        );
        $frame->call = $class->methods[$methodLc];
        $frame->callArgs = [$receiver];
        $frame->callArgEntries = [];
    }

    protected function initStaticCallable(Frame $frame, string $callableName): void
    {
        [$className, $methodName] = explode('::', $callableName, 2);
        $lcClass = $this->resolveClassScopeName($className, $frame);
        if (!isset($this->context->classes[$lcClass])) {
            $this->context->autoloadClass($className);
        }
        if (!isset($this->context->classes[$lcClass])) {
            throw new \LogicException("Call to undefined static method {$callableName}()");
        }
        $frame->staticCallClass = $this->context->classes[$lcClass]->name;
        $methodLc = strtolower($methodName);
        [$class, $methodLc] = $this->resolveStaticMethod($lcClass, $methodLc);
        $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = null;
        if (null !== $frame->block->func && null !== $frame->block->func->class) {
            $callerClassLc = strtolower($frame->block->func->class->value);
        }
        MethodVisibility::assertCallable(
            $vis,
            $callerClassLc,
            $lcClass,
            $class->name,
            $methodName
        );
        $frame->call = $class->methods[$methodLc];
        $frame->callArgs = $this->callArgsForStaticMethod($frame, $lcClass, $frame->call);
    }

    /**
     * @return list<Variable>
     */
    protected function callArgsForStaticMethod(Frame $frame, string $resolvedLc, Func $call): array
    {
        $args = $this->implicitThisArgsForStaticInstanceCall($frame, $call);
        if ([] !== $args) {
            return $args;
        }
        if ($this->isParentClassDispatch($frame, $resolvedLc)) {
            $thisVar = $this->resolveCallerThis($frame);
            if (null !== $thisVar) {
                return [$thisVar];
            }
        }

        return [];
    }

    protected function isParentClassDispatch(Frame $frame, string $resolvedLc): bool
    {
        if (null === $frame->block->func || null === $frame->block->func->class) {
            return false;
        }
        $declaring = strtolower($frame->block->func->class->value);
        if (!isset($this->context->classes[$declaring])) {
            return false;
        }
        $parentLc = $this->context->classes[$declaring]->parentLc;

        return null !== $parentLc && $resolvedLc === $parentLc;
    }

    protected function resolveCallerThis(Frame $frame): ?Variable
    {
        if (null === $frame->block->func || null === $frame->block->func->class) {
            return null;
        }
        if (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return null;
        }
        if (!empty($frame->callArgs)) {
            return $frame->callArgs[0];
        }
        if (!empty($frame->calledArgs)) {
            return $frame->calledArgs[0];
        }
        $idx = $frame->block->slotIndexForVariableName('this');
        if (null !== $idx && isset($frame->scope[$idx])) {
            return $frame->scope[$idx];
        }

        return $frame->block->findVariableByRuntimeName('this', $frame);
    }

    /**
     * Non-parent static calls to instance methods pass $this from the caller (#1858).
     *
     * @return list<Variable>
     */
    protected function implicitThisArgsForStaticInstanceCall(Frame $frame, Func $call): array
    {
        if (!$call instanceof Func\PHP) {
            return [];
        }
        $callee = $call->block;
        if (null === $callee->func || null === $callee->func->class) {
            return [];
        }
        if (($callee->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return [];
        }
        $thisVar = $this->resolveCallerThis($frame);
        if (null === $thisVar) {
            return [];
        }

        return [$thisVar];
    }

    protected function applyTraitUse(ClassEntry $entry, string $traitName): void
    {
        $traitLc = strtolower(ltrim($traitName, '\\'));
        if (!isset($this->context->classes[$traitLc])) {
            $this->context->autoloadClass($traitName);
        }
        if (!isset($this->context->classes[$traitLc])) {
            throw new \LogicException("Trait {$traitName} not found");
        }
        $trait = $this->context->classes[$traitLc];
        if (!$trait->isTrait) {
            throw new \LogicException("{$traitName} is not a trait");
        }
        $entry->usedTraits[$trait->name] = $trait->name;
        foreach ($trait->methods as $name => $method) {
            if (!isset($entry->methods[$name])) {
                $entry->methods[$name] = $method;
                $entry->methodVisibility[$name] = $trait->methodVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (isset($trait->methodAttributeNames[$name])) {
                    $entry->methodAttributeNames[$name] = $trait->methodAttributeNames[$name];
                }
            }
        }
        foreach ($trait->staticProperties as $name => $storage) {
            if (!isset($entry->staticProperties[$name])) {
                $entry->staticProperties[$name] = $storage;
            }
        }
        foreach ($trait->constants as $name => $value) {
            if (!isset($entry->constants[$name])) {
                $entry->constants[$name] = $value;
            }
        }
    }

    protected function inheritFromParent(ClassEntry $entry): void
    {
        if (null === $entry->parentLc || !isset($this->context->classes[$entry->parentLc])) {
            return;
        }
        $parent = $this->context->classes[$entry->parentLc];
        foreach ($parent->interfaces as $iface) {
            if (!in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }
        foreach ($parent->methods as $name => $method) {
            if (!isset($entry->methods[$name])) {
                $entry->methods[$name] = $method;
                $entry->methodVisibility[$name] = $parent->methodVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
            }
        }
        foreach ($parent->staticProperties as $name => $storage) {
            if (!isset($entry->staticProperties[$name])) {
                $entry->staticProperties[$name] = $storage;
            }
        }
        foreach ($parent->constants as $name => $value) {
            if (!isset($entry->constants[$name])) {
                $entry->constants[$name] = $value;
            }
        }
        if (null === $entry->constructor && null !== $parent->constructor) {
            $entry->constructor = $parent->constructor;
        }
        if ($parent->readonly) {
            $entry->readonly = true;
        }
        foreach ($parent->properties as $property) {
            $exists = false;
            foreach ($entry->properties as $existing) {
                if ($existing->name === $property->name) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $entry->properties[] = $property;
            }
        }
    }

    /**
     * @return array{0: ClassEntry, 1: string}
     */
    protected function resolveStaticMethod(string $lcClass, string $methodLc): array
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                break;
            }
            $class = $this->context->classes[$lcClass];
            if (isset($class->methods[$methodLc])) {
                return [$class, $methodLc];
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        throw new \LogicException("Call to undefined static method {$lcClass}::{$methodLc}()");
    }

    protected function initArrayCallable(Frame $frame, Variable $callable): void
    {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \LogicException('Invalid array callable');
        }
        $receiver = $table->findVariable($idx0)->resolveIndirect();
        $methodName = $table->findVariable($idx1)->resolveIndirect()->toString();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Invalid array callable');
        }
        $this->initMethodCall($frame, $receiver, $methodName);
    }

    protected function defineClass(ClassEntry $entry, Block $block): void {
        $frame = $block->getFrame($this->context);
        foreach ($block->opCodes as $op) {
            if ($this->isClassBodyDefaultInitOpcode($op->type)) {
                $this->executeClassBodyDefaultInitOpcode($frame, $op);

                continue;
            }
            switch ($op->type) {
                case OpCode::TYPE_DECLARE_PROPERTY:
                    $name = $frame->scope[$op->arg1];
                    $default = is_null($op->arg2) ? null : $frame->scope[$op->arg2];
                    $entry->properties[] = new VM\ClassProperty(
                        $name->toString(),
                        $default,
                        $frame->scope[$op->arg3]
                    );
                    break;
                case OpCode::TYPE_DECLARE_STATIC_PROPERTY:
                    $name = strtolower($frame->scope[$op->arg1]->toString());
                    $storage = clone $frame->scope[$op->arg3];
                    if (!is_null($op->arg2)) {
                        $storage->copyFrom($frame->scope[$op->arg2]);
                    }
                    $entry->staticProperties[$name] = $storage;
                    break;
                case OpCode::TYPE_DECLARE_METHOD:
                    $name = strtolower($frame->scope[$op->arg1]->toString());
                    $vis = \PHPCfg\Func::FLAG_PUBLIC;
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $vis = MethodVisibility::mask($block->constants[$op->arg3]->toInt());
                    }
                    $entry->methodVisibility[$name] = $vis;
                    if ([] !== $op->attributeNames) {
                        $entry->methodAttributeNames[$name] = $op->attributeNames;
                    }
                    if (null !== $op->block1) {
                        $method = new Func\PHP($entry->name.'::'.$name, $op->block1);
                        $entry->methods[$name] = $method;
                        if ('__construct' === $name) {
                            $entry->constructor = $method;
                        }
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS_CONST:
                    $canonical = $frame->scope[$op->arg1]->toString();
                    $name = strtolower($canonical);
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Class constant value must be a compile-time constant');
                    }
                    $entry->constants[$name] = $block->constants[$op->arg2];
                    if ($entry->isEnum) {
                        $entry->enumCaseCanonicalNames[$name] = $canonical;
                    }
                    break;
                case OpCode::TYPE_USE_TRAIT:
                    $this->applyTraitUse($entry, $frame->scope[$op->arg1]->toString());
                    break;
                default:
                    throw new \LogicException(
                        'Other class body types are not jittable for now: '.opcode_type_name($op->type)
                    );
            }
        }
    }

    private function isClassBodyDefaultInitOpcode(int $type): bool
    {
        return OpCode::TYPE_INIT_ARRAY === $type
            || OpCode::TYPE_ADD_ARRAY_ELEMENT === $type
            || OpCode::TYPE_ARRAY_SPREAD === $type;
    }

    private function executeClassBodyDefaultInitOpcode(Frame $frame, OpCode $op): void
    {
        switch ($op->type) {
            case OpCode::TYPE_INIT_ARRAY:
                $result = $frame->scope[$op->arg1];
                $result->newArray();
                if (is_null($op->arg2)) {
                    break;
                }
                // Fall through intentional
            case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                $result = $frame->scope[$op->arg1];
                $ht = $result->toArray();
                if (is_null($op->arg3)) {
                    $ht->append($frame->scope[$op->arg2]);

                    break;
                }
                $key = $frame->scope[$op->arg3]->resolveIndirect();
                if ($key->is(Variable::TYPE_INTEGER)) {
                    $ht->addIndex($key->toInt(), $frame->scope[$op->arg2]);
                } else {
                    $ht->add($key->toString(), $frame->scope[$op->arg2]);
                }
                break;
            case OpCode::TYPE_ARRAY_SPREAD:
                $result = $frame->scope[$op->arg1];
                $source = $frame->scope[$op->arg2]->resolveIndirect();
                if (Variable::TYPE_ARRAY !== $source->type) {
                    throw new \LogicException(
                        Variable::TYPE_NULL === $source->type
                            ? 'Cannot spread null'
                            : 'Only arrays can be spread'
                    );
                }
                $result->toArray()->spreadFrom($source->toArray());
                break;
            default:
                throw new \LogicException(
                    'Unexpected class body init opcode: '.opcode_type_name($op->type)
                );
        }
    }

    private function enforceReturnType(Frame $frame, ?Variable $value): void
    {
        $block = $frame->block;
        if (null === $block) {
            return;
        }
        if ($block->returnTypeNever) {
            TypeCheck::assertNeverReturn();

            return;
        }
        if ($block->returnTypeVoid) {
            TypeCheck::assertVoidReturn($value);

            return;
        }
        if (null === $block->returnTypeConstraint || null === $value) {
            return;
        }
        $strict = $block->strictTypes;
        TypeCheck::coerceReturn($value, $strict, $block->returnTypeConstraint);
    }

}
