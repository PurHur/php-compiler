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
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

class VM {
    const SUCCESS = 1;
    const FAILURE = 2;

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
                    $arg2->copyFrom($arg3);
                    $arg1->copyFrom($arg3);
                    $strict = null !== $frame->parent
                        ? $frame->parent->block->strictTypes
                        : $frame->block->strictTypes;
                    TypeCheck::coercePropertyWrite($arg2, $strict);
                    break;
                case OpCode::TYPE_ASSIGN_REF:
                    $lhs = $frame->scope[$op->arg1];
                    $rhs = $frame->scope[$op->arg2]->resolveIndirect();
                    $lhs->indirect($rhs);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Global name must be a compile-time constant');
                    }
                    $globalName = $frame->block->constants[$op->arg2]->toString();
                    $frame->scope[$op->arg1]->indirect($this->context->ensureGlobal($globalName));
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
                            $this->context->errors->undefinedArrayKey($arg3);
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
                    $frame = $op->block1->getFrame(
                        $this->context,
                        $frame 
                    );
                    goto restart;
                case OpCode::TYPE_JUMPIF:
                    $arg1 = $frame->scope[$op->arg1]->toBool();
                    if ($arg1) {
                        $frame = $op->block1->getFrame($this->context, $frame);
                    } else {
                        $frame = $op->block2->getFrame($this->context, $frame);
                    }
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
                case OpCode::TYPE_CLASS_CONST_FETCH:
                    $className = $frame->scope[$op->arg2]->toString();
                    $lcClass = strtolower($className);
                    if ('self' === $lcClass || 'static' === $lcClass) {
                        if (null === $frame->block->func || null === $frame->block->func->class) {
                            return $this->raise('self:: used outside of class scope', $frame);
                        }
                        $lcClass = strtolower($frame->block->func->class->name);
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
                    $frame->scope[$op->arg1]->copyFrom($classEntry->constants[$constName]);
                    break;
                case OpCode::TYPE_INSTANCEOF:
                    $value = $frame->scope[$op->arg2]->resolveIndirect();
                    $className = strtolower($frame->scope[$op->arg3]->toString());
                    $matches = false;
                    if (Variable::TYPE_OBJECT === $value->type) {
                        $matches = strtolower($value->toObject()->class->name) === $className;
                    }
                    $frame->scope[$op->arg1]->bool($matches);
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_FETCH:
                    $lcClass = $this->resolveStaticClassName(
                        $frame->scope[$op->arg2]->toString(),
                        $frame
                    );
                    if (!isset($this->context->classes[$lcClass])) {
                        $classLabel = $frame->scope[$op->arg2]->toString();
                        return $this->raise("Unknown class for static property fetch: {$classLabel}", $frame);
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
                case OpCode::TYPE_UNSET:
                    if (null !== $op->arg1 && isset($frame->scope[$op->arg1])) {
                        $frame->scope[$op->arg1]->null();
                    }
                    break;
                case OpCode::TYPE_RETURN_VOID:
                    if (!is_null($frame->returnVar)) {
                        $frame->returnVar->null();
                    }
                    if ($frame->ephemeral && null !== $frame->parent) {
                        $frame = $frame->parent;
                        goto restart;
                    }
                    goto nextframe;
                case OpCode::TYPE_RETURN:
                    if (!is_null($frame->returnVar)) {
                        $frame->returnVar->copyFrom($frame->scope[$op->arg1]);
                    }
                    if ($frame->ephemeral && null !== $frame->parent) {
                        $frame = $frame->parent;
                        goto restart;
                    }
                    goto nextframe;
                case OpCode::TYPE_FUNCDEF:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->functions[$lcname])) {
                        throw new \LogicException("Duplicate function definition for $lcname()");
                    }
                    $this->context->declareFunction(new Func\PHP($name, $op->block1));
                    break;
                case OpCode::TYPE_FUNCCALL_INIT:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (!isset($this->context->functions[$lcname])) {
                        throw new \LogicException("Call to undefined function $lcname()");
                    }
                    $frame->call = $this->context->functions[$lcname];
                    $frame->callArgs = [];
                    break;
                case OpCode::TYPE_METHODCALL_INIT:
                    $receiver = $frame->scope[$op->arg1]->resolveIndirect();
                    if ($receiver->type !== Variable::TYPE_OBJECT) {
                        throw new \LogicException('Method call on non-object');
                    }
                    $methodName = strtolower($frame->scope[$op->arg2]->toString());
                    $class = $receiver->toObject()->class;
                    if (!isset($class->methods[$methodName])) {
                        throw new \LogicException("Call to undefined method {$class->name}::{$methodName}()");
                    }
                    $vis = $class->methodVisibility[$methodName] ?? \PHPCfg\Func::FLAG_PUBLIC;
                    $callerClassLc = null;
                    if (null !== $frame->block->func && null !== $frame->block->func->class) {
                        $callerClassLc = strtolower($frame->block->func->class->value);
                    }
                    MethodVisibility::assertCallable(
                        $vis,
                        $callerClassLc,
                        strtolower($class->name),
                        $class->name,
                        $frame->scope[$op->arg2]->toString()
                    );
                    $frame->call = $class->methods[$methodName];
                    $frame->callArgs = [$receiver];
                    break;
                case OpCode::TYPE_ARG_SEND:
                    $frame->callArgs[] = $frame->scope[$op->arg1];
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    if (is_null($frame->call)) {
                        // Used for null constructors, etc
                        break;
                    }
                    $new = $frame->call->getFrame($this->context, $frame);
                    if ($op->type === OpCode::TYPE_FUNCCALL_EXEC_RETURN) {
                        $new->returnVar = $frame->scope[$op->arg1];
                    }
                    $new->calledArgs = $frame->callArgs;
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
                    if (null !== $frame->block->func && null !== $frame->block->func->class) {
                        ++$recvIdx;
                    }
                    if (array_key_exists($recvIdx, $frame->calledArgs)) {
                        $arg1->copyFrom($frame->calledArgs[$recvIdx]);
                    } elseif (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                        $arg1->copyFrom($frame->block->constants[$op->arg3]);
                    } else {
                        throw new \LogicException('Missing required argument ' . $op->arg2);
                    }
                    $strict = null !== $frame->parent
                        ? $frame->parent->block->strictTypes
                        : $frame->block->strictTypes;
                    TypeCheck::coerceParameter($arg1, $strict);
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
                case OpCode::TYPE_DECLARE_CLASS:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate class definition for $name");
                    }
                    $classEntry = new ClassEntry($name);
                    self::defineClass($classEntry, $op->block1);
                    $this->context->classes[$lcname] = $classEntry;
                    break;
                case OpCode::TYPE_NEW:
                    $result = $frame->scope[$op->arg1];
                    $name = $frame->scope[$op->arg2]->toString();
                    $lcname = strtolower($name);
                    if (!isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Attempting to instantiate non-existing class $name");
                    }
                    $class = $this->context->classes[$lcname];
                    $result->object(new ObjectEntry($class));
                    $frame->call = $result->toObject()->constructor;
                    $frame->callArgs = [$result];
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                    $result = $frame->scope[$op->arg1];
                    $var = $frame->scope[$op->arg2]->resolveIndirect();
                    $name = $frame->scope[$op->arg3]->toString();
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
                case OpCode::TYPE_ITER_RESET:
                    $container = $frame->scope[$op->arg1]->resolveIndirect();
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator reset requires an array');
                    }
                    $container->toArray()->iterReset();
                    break;
                case OpCode::TYPE_ITER_VALID:
                    $container = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator valid requires an array');
                    }
                    $frame->scope[$op->arg1]->bool($container->toArray()->iterValid());
                    break;
                case OpCode::TYPE_ITER_KEY:
                    $container = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator key requires an array');
                    }
                    $frame->scope[$op->arg1]->copyFrom($container->toArray()->iterCurrentKey());
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    $container = $frame->scope[$op->arg2]->resolveIndirect();
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
                case OpCode::TYPE_FINALLY:
                    if (null !== $op->block2) {
                        $frame = $op->block2->getFrame($this->context, $frame);
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_THROW:
                    $thrown = $frame->scope[$op->arg1]->resolveIndirect();
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
                default:
                    throw new \LogicException("VM OpCode Not Implemented: " . opcode_type_name($op->type));
            }
        }
        if ($frame->ephemeral) {
            $this->context->scriptStack->pop();
            if (null !== $frame->parent) {
                $frame = $frame->parent;
                goto restart;
            }
            goto nextframe;
        }
        return self::SUCCESS;
    }

    protected function raise(string $message, Frame $frame): int
    {
        $where = '' !== $frame->scriptPath ? $frame->scriptPath : 'script';
        throw new \LogicException($message.' in '.$where);
    }

    protected function resolveStaticClassName(string $className, Frame $frame): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass || 'static' === $lcClass) {
            if (null === $frame->block->func || null === $frame->block->func->class) {
                throw new \LogicException('self:: used outside of class scope');
            }

            return strtolower($frame->block->func->class->name);
        }

        return $lcClass;
    }

    protected function defineClass(ClassEntry $entry, Block $block): void {
        $frame = $block->getFrame($this->context);
        // TODO
        foreach ($block->opCodes as $op) {
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
                    if (null !== $op->block1) {
                        $method = new Func\PHP($entry->name.'::'.$name, $op->block1);
                        $entry->methods[$name] = $method;
                        if ('__construct' === $name) {
                            $entry->constructor = $method;
                        }
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS_CONST:
                    $name = strtolower($frame->scope[$op->arg1]->toString());
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Class constant value must be a compile-time constant');
                    }
                    $entry->constants[$name] = $block->constants[$op->arg2];
                    break;
                default:
                    var_dump($op);
                    throw new \LogicException('Other class body types are not jittable for now');
            }
            
        }
    }

}
