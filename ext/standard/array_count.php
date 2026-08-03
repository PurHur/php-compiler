<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ArrayCountRecursiveRuntime;
use PHPCompiler\JIT\Builtin\ArrayCountRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * count() for arrays (subset of PHP; php-src ext/standard/array.c).
 */
final class array_count extends Internal
{
    public function __construct(string $name = 'count')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/array.c — ArgumentCountError (#21964).
        $this->requireArgCountRange($frame, 'count', 1, 2);
        $argc = \count($frame->calledArgs);
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->vmContext) {
            throw new \LogicException('count() requires VM context in this compiler build');
        }
        if (Variable::TYPE_NULL === $v->type) {
            // php-src 8.0+: count()/sizeof() always TypeError on null (not soft-coerce).
            // Zend 8.2 reference matches; do not gate on caller strict_types (#21914, re-#21771).
            throw new \TypeError(
                $this->name.'(): Argument #1 ($value) must be of type Countable|array, null given'
            );
        }
        $mode = VmArray::COUNT_NORMAL;
        if (2 === $argc) {
            $modeArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeArg->type) {
                throw new \TypeError('count(): Argument #2 ($mode) must be of type int');
            }
            $mode = $modeArg->toInt();
            if (VmArray::COUNT_NORMAL !== $mode && VmArray::COUNT_RECURSIVE !== $mode) {
                throw new \LogicException(
                    'count(): Parameter must be an integer or use the COUNT_RECURSIVE flag'
                );
            }
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            $ht = $v->toArray();
            $result = VmArray::COUNT_RECURSIVE === $mode
                ? VmArray::countRecursive($ht, $frame)
                : $ht->getNumElements();
        } else {
            $result = VmArray::countValue($frame->vmContext, $v, $this->name);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($result);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        TypeErrorRaise::ensureLinked($context);
        if (!$this->requireArgCountRangeJit($context, $args, 'count', 1, 2)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $argc = \count($args);
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            // php-src 8.0+: always TypeError on null (#21914).
            TypeErrorRaise::emitRaise(
                $context,
                $this->name.'(): Argument #1 ($value) must be of type Countable|array, null given'
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        // User-script AOT: SimpleXMLElement child views fold via host tree (#26863).
        $sxeCount = \PHPCompiler\ext\simplexml\JitSimpleXmlUserScript::tryFoldCount($context, $args[0]);
        if (null !== $sxeCount) {
            return $sxeCount;
        }
        $recursive = false;
        if (2 === $argc) {
            $modeLit = JitLongArg::compileTimeLiteral($args[1]);
            if (null === $modeLit) {
                throw new \LogicException('count() mode must be a compile-time integer in this compiler build');
            }
            if (VmArray::COUNT_RECURSIVE === $modeLit) {
                $recursive = true;
            } elseif (VmArray::COUNT_NORMAL !== $modeLit) {
                throw new \LogicException(
                    'count(): Parameter must be an integer or use the COUNT_RECURSIVE flag'
                );
            }
        }
        if ($recursive) {
            if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY
                || JITVariable::TYPE_HASHTABLE === $args[0]->type
                || JITVariable::TYPE_VALUE === $args[0]->type
                || JitValueBox::isValueOperand($args[0])
            ) {
                return ArrayCountRecursiveRuntime::countRecursive($context, $args[0]);
            }
            $this->emitCountTypeError($context, $args[0]);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromInteger($args[0]->nextFreeElement, 'int64');
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type) {
            return ArrayCountRuntime::numElements($context, $args[0]);
        }
        // Boxed values may hold arrays OR Countable objects (SplFixedArray::fromArray; #26793).
        // Must branch on the value-box type tag — unconditional Countable dispatch treats
        // plain arrays as SplFixedArray and aborts under thin AOT (#27294 / re-#26793).
        if (JITVariable::TYPE_VALUE === $args[0]->type || JitValueBox::isValueOperand($args[0])) {
            return $this->countBoxedValue($context, $args[0]);
        }
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            $countable = $this->tryCompileCountableCount($context, $args[0]);
            if (null !== $countable) {
                return $countable;
            }
        }
        $this->emitCountTypeError($context, $args[0]);

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    /**
     * count() on a `__value__*` slot — array HT vs Countable object (#26793, #27294).
     */
    private function countBoxedValue(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $htTag = $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false);
        $objTag = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $isHt = $context->builder->icmp(Builder::INT_EQ, $kind, $htTag);
        $isObj = $context->builder->icmp(Builder::INT_EQ, $kind, $objTag);

        $htBlock = BasicBlockHelper::append($context, 'count_box_ht');
        $afterHt = BasicBlockHelper::append($context, 'count_box_after_ht');
        $objBlock = BasicBlockHelper::append($context, 'count_box_obj');
        $errBlock = BasicBlockHelper::append($context, 'count_box_err');
        $merge = BasicBlockHelper::append($context, 'count_box_merge');
        $context->builder->branchIf($isHt, $htBlock, $afterHt);

        $context->builder->positionAtEnd($htBlock);
        $htCount = ArrayCountRuntime::numElements($context, $arg);
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($afterHt);
        $context->builder->branchIf($isObj, $objBlock, $errBlock);

        $context->builder->positionAtEnd($objBlock);
        $countable = $this->tryCompileCountableCount($context, $arg);
        $objCount = null !== $countable
            ? $countable
            : $i64->constInt(0, false);
        if (null === $countable) {
            $this->emitCountTypeError($context, $arg);
        }
        $objEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($errBlock);
        $this->emitCountTypeError($context, $arg);
        $errCount = $i64->constInt(0, false);
        $errEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i64, 'count_box_phi');
        $phi->addIncoming($htCount, $htEnd);
        $phi->addIncoming($objCount, $objEnd);
        $phi->addIncoming($errCount, $errEnd);

        return $phi;
    }

    /**
     * count($obj) for Countable — runtime class_id dispatch (KIND_VARIABLE locals; #26793).
     */
    private function tryCompileCountableCount(Context $context, JITVariable $arg): ?Value
    {
        $candidates = [];
        foreach ($context->type->object->allClassNamesById() as $classId => $className) {
            $classLc = strtolower(ltrim((string) $className, '\\'));
            if (!\in_array(
                'countable',
                $context->type->object->allInterfacesForClassLc($classLc),
                true
            )) {
                continue;
            }
            $proxyName = $classLc.'::count';
            if (!$context->functionIsRegistered($proxyName)) {
                continue;
            }
            $candidates[(int) $classId] = $context->resolveFunctionProxy($proxyName);
        }
        // Ensure SplFixedArray is a candidate even if interface seeding lagged (#26793).
        if (
            $context->type->object->hasDeclaredClass('SplFixedArray')
            && $context->functionIsRegistered('splfixedarray::count')
        ) {
            $sfaId = $context->type->object->lookup('SplFixedArray');
            $candidates[$sfaId] = $context->resolveFunctionProxy('splfixedarray::count');
        }
        if ([] === $candidates) {
            return null;
        }
        // Single candidate: call directly (avoids RuntimeIndirect class_id mismatch on
        // KIND_VARIABLE object slots that still hold the right instance).
        if (1 === \count($candidates)) {
            $proxy = reset($candidates);
            assert($proxy instanceof \PHPCompiler\JIT\Call);
            $box = $proxy->call($context, $arg);
            $ptr = JitValueBox::normalizeValuePtr($context, $box);

            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $ptr
            );
        }
        $box = (new \PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall(
            $arg,
            'count',
            $candidates
        ))->call($context, $arg);
        $ptr = JitValueBox::normalizeValuePtr($context, $box);

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $ptr
        );
    }

    private function emitCountTypeError(Context $context, JITVariable $arg): void
    {
        TypeErrorRaise::emitRaise(
            $context,
            $this->name.'(): Argument #1 ($value) must be of type Countable|array, '
            .$this->jitArgTypeLabel($arg).' given'
        );
    }

    private function jitArgTypeLabel(JITVariable $arg): string
    {
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            case JITVariable::TYPE_OBJECT:
                return $this->jitCompileTimeObjectLabel($arg);
            default:
                return 'mixed';
        }
    }

    private function jitCompileTimeObjectLabel(JITVariable $arg): string
    {
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $this->context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return 'object';
        }
        $classIdVal = $this->context->builder->load(
            $this->context->builder->structGep($arg->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return 'object';
        }

        return $this->context->type->object->classNameForId((int) $classIdVal->getConstantValue());
    }
}
