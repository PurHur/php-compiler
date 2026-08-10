<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCfg\Func;
use PHPLLVM\Builder;

/**
 * Class/enum return type checks at JIT/AOT return sites (#17222, zend_type_hold.c).
 *
 * {@see \PHPCompiler\VM\HashTable} is special: NestedJIT lowers `: HashTable` to the
 * `__hashtable__*` ABI (#21109), so return operands are native hashtables / i64 pointers —
 * not `__object__*`. Treating them as ordinary class returns TypeErrors
 * `HashTable, int returned` (#20652, #21888 / SuperglobalRefreshJitHelper).
 *
 * {@see \PHPCompiler\VM\Variable} is the same shape: NestedJIT lowers `: Variable` to
 * `__value__*` (#16565 / #20785). Object-style ClassReturnCheck then TypeErrors
 * `Variable, int returned` on value-box returns (GetObjectVarsJitHelper — #26797).
 */
final class ClassReturnCheck
{
    /**
     * @return bool false when a TypeError path was emitted (caller must not emit ret)
     */
    public static function enforce(
        Context $context,
        Block $block,
        Variable $return
    ): bool {
        if (null === $block->returnClassConstraint) {
            return true;
        }
        if (self::generatorHasTraversableReturnTypeLabel($block)) {
            return true;
        }
        $expected = ltrim($block->returnDeclaredTypeLabel ?? $block->returnClassConstraint, '\\');
        $callableName = self::callableName($block->func);
        if (self::isVmHashTableClass($expected)
            || self::isVmHashTableClass($block->returnClassConstraint)
        ) {
            return self::enforceVmHashTableReturn($context, $return, $callableName, $expected);
        }
        if (self::isVmVariableClass($expected)
            || self::isVmVariableClass($block->returnClassConstraint)
        ) {
            return self::enforceVmVariableReturn($context, $return, $callableName, $expected);
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);
        $scalarGiven = self::scalarGivenLabel($return);
        if (null !== $scalarGiven) {
            self::raiseReturnTypeError($context, $callableName, $expected, $scalarGiven);

            return false;
        }
        if (Variable::TYPE_VALUE === $return->type) {
            return self::enforceValueBox($context, $objectType, $return, $callableName, $expected);
        }
        $classLc = strtolower(ltrim($block->returnClassConstraint, '\\'));
        $ok = $objectType->emitInstanceOf($return, $classLc);

        return self::branchOnBoolOrRaise($context, $ok, $callableName, $expected, $return, $objectType);
    }

    /** NestedJIT / param ABI name for {@see \PHPCompiler\VM\HashTable} (#21109). */
    public static function isVmHashTableClass(string $name): bool
    {
        $lc = strtolower(ltrim($name, '\\'));

        return 'phpcompiler\\vm\\hashtable' === $lc
            || str_ends_with($lc, '\\vm\\hashtable')
            || 'hashtable' === $lc;
    }

    /** NestedJIT / param ABI name for {@see \PHPCompiler\VM\Variable} (#16565 / #26797). */
    public static function isVmVariableClass(string $name): bool
    {
        $lc = strtolower(ltrim($name, '\\'));

        return 'phpcompiler\\vm\\variable' === $lc
            || str_ends_with($lc, '\\vm\\variable')
            || 'variable' === $lc;
    }

    /**
     * Accept native hashtable ABI returns for `: HashTable` (#21888, #20652).
     *
     * @return bool false when a TypeError path was emitted
     */
    private static function enforceVmHashTableReturn(
        Context $context,
        Variable $return,
        ?string $callableName,
        string $expected
    ): bool {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        // __hashtable__* / NestedJIT i64 pointer / HashTable object / value-box hashtable
        if (Variable::TYPE_HASHTABLE === $return->type
            || Variable::TYPE_NATIVE_LONG === $return->type
            || Variable::TYPE_OBJECT === $return->type
            || Variable::TYPE_VALUE === $return->type
            || 0 !== ($return->type & Variable::IS_NATIVE_ARRAY)
        ) {
            return true;
        }
        if (Variable::TYPE_NULL === $return->type) {
            self::raiseReturnTypeError($context, $callableName, $expected, 'null');

            return false;
        }
        $scalarGiven = self::scalarGivenLabel($return);
        if (null !== $scalarGiven) {
            self::raiseReturnTypeError($context, $callableName, $expected, $scalarGiven);

            return false;
        }

        return true;
    }

    /**
     * Accept NestedJIT `__value__*` ABI returns for `: Variable` (#26797 / peer #21888).
     *
     * @return bool false when a TypeError path was emitted
     */
    private static function enforceVmVariableReturn(
        Context $context,
        Variable $return,
        ?string $callableName,
        string $expected
    ): bool {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        // Boxed slot / native long pointer / object wrap / hashtable payload
        if (Variable::TYPE_VALUE === $return->type
            || Variable::TYPE_NATIVE_LONG === $return->type
            || Variable::TYPE_OBJECT === $return->type
            || Variable::TYPE_HASHTABLE === $return->type
            || Variable::TYPE_STRING === $return->type
            || Variable::TYPE_NATIVE_BOOL === $return->type
            || Variable::TYPE_NATIVE_DOUBLE === $return->type
            || 0 !== ($return->type & Variable::IS_NATIVE_ARRAY)
        ) {
            return true;
        }
        if (Variable::TYPE_NULL === $return->type) {
            self::raiseReturnTypeError($context, $callableName, $expected, 'null');

            return false;
        }
        $scalarGiven = self::scalarGivenLabel($return);
        if (null !== $scalarGiven) {
            self::raiseReturnTypeError($context, $callableName, $expected, $scalarGiven);

            return false;
        }

        return true;
    }

    private static function enforceValueBox(
        Context $context,
        ObjectType $objectType,
        Variable $return,
        ?string $callableName,
        string $expected
    ): bool {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $return);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $objectBlock = $fn->appendBasicBlock('class_return_value_object');
        $scalarBlock = $fn->appendBasicBlock('class_return_value_scalar');
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $objectBlock, $scalarBlock);
        $context->builder->positionAtEnd($scalarBlock);
        self::raiseReturnTypeError($context, $callableName, $expected, 'int');
        self::emitUnreachableIfNeeded($context);
        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $classLc = strtolower(ltrim($expected, '\\'));
        $ok = $objectType->emitInstanceOf($objVar, $classLc);

        return self::branchOnBoolOrRaise($context, $ok, $callableName, $expected, $objVar, $objectType);
    }

    private static function emitUnreachableIfNeeded(Context $context): void
    {
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
    }

    private static function branchOnBoolOrRaise(
        Context $context,
        Variable $ok,
        ?string $callableName,
        string $expected,
        Variable $return,
        ObjectType $objectType
    ): bool {
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $pass = $fn->appendBasicBlock('class_return_ok');
        $fail = $fn->appendBasicBlock('class_return_fail');
        $resume = $fn->appendBasicBlock('class_return_resume');
        $bool = $context->helper->loadValue($ok);
        $context->builder->branchIf($bool, $pass, $fail);
        $context->builder->positionAtEnd($fail);
        $scalarGiven = self::scalarGivenLabel($return);
        if (null !== $scalarGiven) {
            self::raiseReturnTypeError($context, $callableName, $expected, $scalarGiven);
        } else {
            self::emitObjectFailureMessage($context, $objectType, $return, $callableName, $expected);
        }
        $context->builder->positionAtEnd($pass);
        $context->builder->branch($resume);
        $context->builder->positionAtEnd($resume);

        return true;
    }

    private static function raiseReturnTypeError(
        Context $context,
        ?string $callableName,
        string $expected,
        string $given
    ): void {
        $message = "Return value must be of type {$expected}, {$given} returned";
        if (null !== $callableName && '' !== $callableName) {
            $message = "{$callableName}(): {$message}";
        }
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        self::emitUnreachableIfNeeded($context);
    }

    private static function emitObjectFailureMessage(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        ?string $callableName,
        string $expected
    ): void {
        unset($objectType);
        $given = self::scalarGivenLabel($arg);
        if (null !== $given) {
            self::raiseReturnTypeError($context, $callableName, $expected, $given);

            return;
        }
        self::raiseReturnTypeError($context, $callableName, $expected, 'object');
    }

    private static function generatorHasTraversableReturnTypeLabel(Block $block): bool
    {
        if (!$block->isGenerator) {
            return false;
        }
        $returnLabel = ltrim(
            $block->returnDeclaredTypeLabel ?? $block->returnClassConstraint ?? '',
            '\\'
        );
        if ('' === $returnLabel) {
            return false;
        }

        // Mirror VM (#16141, #26468): wrapper types only — not getReturn()/body completion.
        // Bare `: iterable` keeps returnDeclaredTypeLabel=iterable with Traversable|array DNF (#29888).
        return \in_array($returnLabel, ['Generator', 'Iterator', 'Traversable', 'iterable', 'object'], true);
    }

    /** @internal used by JIT return epilogues for generator `: iterable` DNF skips (#29888). */
    public static function generatorSkipsBodyReturnCheck(Block $block): bool
    {
        return self::generatorHasTraversableReturnTypeLabel($block);
    }

    private static function callableName(?Func $func): ?string
    {
        if (null === $func) {
            return null;
        }
        if (null !== $func->class) {
            $className = $func->class->value ?? null;
            if (is_string($className) && '' !== $className) {
                return $className.'::'.$func->name;
            }
        }

        return $func->name;
    }

    private static function scalarGivenLabel(Variable $arg): ?string
    {
        return match ($arg->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE => 'array',
            default => null,
        };
    }

    private static function objectPointer(Context $context, Variable $arg): \PHPLLVM\Value
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        }

        return $context->getTypeFromString('__object__*')->constNull();
    }
}
