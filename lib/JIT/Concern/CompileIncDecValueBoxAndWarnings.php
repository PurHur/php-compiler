<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Inc/dec value-box reads, no-effect warnings, string deprecations, and resource
 * guards for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileIncDecAndConcatFlatten} so gen-0 split-TU can hollow
 * a smaller TU. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_operators.c (increment_function / decrement_function),
 * Zend/zend_vm_def.h (ZEND_POST_INC / ZEND_PRE_INC / …) — move-only Concern extract;
 * no new C ABI.
 */
trait CompileIncDecValueBoxAndWarnings
{
    /** Coerce null value-box operands to 0 before ++; decrement uses raw readLong (#7435). */
    private function readIncDecValueBoxLong(
        JIT\Variable $read,
        PHPLLVM\Value $readPtr,
        bool $increment
    ): PHPLLVM\Value {
        if (!$increment) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $readPtr
            );
        }
        if (JIT\Variable::TYPE_NULL === $read->type || ($read->isNullConstant ?? false)) {
            // int64 like __value__readLong — $readPtr->typeOf() is the value-box
            // POINTER type and mistypes every consumer (verifier phi mismatch).
            return $this->context->getTypeFromString('int64')->constInt(0, false);
        }
        if (!JIT\JitValueBox::isValueOperand($read)) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $readPtr
            );
        }
        $isNull = JIT\JitValueCompare::valueBoxIsNull($this->context, $read);
        $zero = $this->context->getTypeFromString('int64')->constInt(0, false);
        $readLong = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $readPtr
        );
        $okBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_null_coerce_ok');
        $nullBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_null_coerce_null');
        $mergeBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_null_coerce_merge');
        $this->context->builder->branchIf($isNull, $nullBlock, $okBlock);
        $this->context->builder->positionAtEnd($nullBlock);
        $this->context->builder->branch($mergeBlock);
        $this->context->builder->positionAtEnd($okBlock);
        $this->context->builder->branch($mergeBlock);
        $this->context->builder->positionAtEnd($mergeBlock);
        $phi = $this->context->builder->phi($readLong->typeOf(), 'incdec_null_coerced');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($readLong, $okBlock);

        return $phi;
    }

    /**
     * PHP 8.3+ E_WARNING for no-op bool ++/-- or null -- (zend_operators.c, #26378).
     */
    private function emitIncDecNoEffectWarning(bool $increment, string $typeName): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsIncDecNoEffectWarning()) {
            return;
        }
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        JIT\Builtin\StringTriggerError::ensureLinked($this->context);
        $message = VM\Variable::incDecNoEffectWarningMessage(
            $increment ? 'Increment' : 'Decrement',
            $typeName
        );
        $i8p = $this->context->getTypeFromString('int8*');
        $sizeT = $this->context->getTypeFromString('size_t');
        $i32 = $this->context->getTypeFromString('int32');
        $msgPtr = $this->context->builder->pointerCast(
            $this->context->constantFromString($message),
            $i8p
        );
        $emptyFile = $this->context->builder->pointerCast(
            $this->context->constantFromString(''),
            $i8p
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(VM\ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    /**
     * PHP 8.3+ E_DEPRECATED for ++ on empty / non-alphanumeric string (zend_operators.c, #29658).
     */
    private function emitStringIncrementDeprecationsIfNeeded(string $literal): void
    {
        if (is_numeric($literal)) {
            return;
        }
        if ('' !== $literal && \PHPCompiler\ext\standard\VmString::onlyAsciiAlphanumeric($literal)) {
            return;
        }
        $this->emitIncDecStringDeprecation('Increment on non-alphanumeric string is deprecated');
    }

    /**
     * PHP 8.3+ E_DEPRECATED for -- on empty or non-numeric string (#29088, #29658).
     */
    private function emitStringDecrementDeprecationsIfNeeded(string $literal): void
    {
        if ('' === $literal) {
            $this->emitIncDecStringDeprecation('Decrement on empty string is deprecated as non-numeric');

            return;
        }
        if (!is_numeric($literal)) {
            $this->emitNonNumericStringDecrementDeprecation();
        }
    }

    /**
     * PHP 8.3+ E_DEPRECATED for -- on non-numeric string (zend_operators.c, #29088).
     */
    private function emitNonNumericStringDecrementDeprecation(): void
    {
        $this->emitIncDecStringDeprecation(
            'Decrement on non-numeric string has no effect and is deprecated'
        );
    }

    /**
     * Emit a compile-time E_DEPRECATED for string ++/-- (same profile gate as #26378 / #29088).
     */
    private function emitIncDecStringDeprecation(string $message): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsIncDecNoEffectWarning()) {
            return;
        }
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        JIT\Builtin\StringTriggerError::ensureLinked($this->context);
        $i8p = $this->context->getTypeFromString('int8*');
        $sizeT = $this->context->getTypeFromString('size_t');
        $i32 = $this->context->getTypeFromString('int32');
        $msgPtr = $this->context->builder->pointerCast(
            $this->context->constantFromString($message),
            $i8p
        );
        $emptyFile = $this->context->builder->pointerCast(
            $this->context->constantFromString(''),
            $i8p
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(VM\ErrorReporter::E_DEPRECATED, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    /** True when ++/-- should read/write a boxed local slot via __value__* helpers. */
    private function isIncDecValueBoxLvalue(JIT\Variable $read, ?Operand $readOp): bool
    {
        if (Variable::TYPE_VALUE !== $read->type) {
            return false;
        }
        if (Variable::KIND_VARIABLE === $read->kind || $read->functionStaticGlobal) {
            return true;
        }

        // Typed locals can be KIND_VALUE rvalues bound to a scope slot (#23840).
        return $readOp instanceof Operand && $this->context->hasVariableOpInScopes($readOp);
    }

    /** Reject ++/-- on stream/dir handles (issue #6396, zend_operators.c). */
    private function guardIncDecResourceOperand(
        JIT\Variable $read,
        bool $increment,
        ?Operand $readOp = null
    ): void
    {
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        $longVal = null;
        if (JIT\Variable::TYPE_NATIVE_LONG === $read->type) {
            $longVal = $this->context->helper->loadValue($read);
        } elseif (JIT\Variable::TYPE_VALUE === $read->type) {
            $readPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $read);
            $longVal = $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $readPtr
            );
        }
        if (null === $longVal) {
            return;
        }
        // StreamLifecycle + StringDir: is_resource must see JitOpenStreamHandles (#23777).
        JIT\Builtin\StreamLifecycleRuntime::ensureLinked($this->context);
        JIT\Builtin\StringDir::ensureLinked($this->context);
        // Provenance proves this operand cannot be a handle — fold the registry walk to false while
        // keeping the ok-block split script-scope ++/-- requires (#23840, #23841).
        $provenNonResource = $readOp instanceof Operand
            && JIT\IncDecResourceProvenance::cannotBeResource($readOp);
        $isRes = $provenNonResource
            ? $this->context->getTypeFromString('int1')->constInt(0, false)
            : JIT\JitValueCompare::nativeLongIsResource($this->context, $longVal);
        ++self::$blockNumber;
        $suffix = (string) self::$blockNumber;
        $okBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_res_ok_'.$suffix);
        $errBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_res_err_'.$suffix);
        $this->context->builder->branchIf($isRes, $errBlock, $okBlock);
        $this->context->builder->positionAtEnd($errBlock);
        // Catchable inside active try/catch; fatal only when uncaught (#23777).
        JIT\ExceptionBridge::emitTypeErrorAndAbort(
            $this->context,
            $increment ? 'Cannot increment resource' : 'Cannot decrement resource'
        );
        $this->context->builder->positionAtEnd($okBlock);
    }
}
