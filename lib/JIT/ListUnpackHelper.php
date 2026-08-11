<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\ext\standard\JitArrayIsList;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\ListUnpackRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\ArraySpread;
use PHPCompiler\VM\VmUnset;
use PHPTypes\Type;
use PHPLLVM\Builder;

/**
 * Runtime guard for `list()` / `[]` destructuring on non-array RHS (#4325, #4308, #10486);
 * spread uses isList (#4298, #4841). Object RHS → Zend Error (#25096).
 *
 * SSOT: {@see \PHPCompiler\VM\ListUnpackJitHelper}
 */
final class ListUnpackHelper
{
    public const TYPE_ERROR_MESSAGE = 'Cannot unpack array with string keys';

    public const CALL_UNPACK_NON_ARRAY_MESSAGE = 'Only arrays and Traversables can be unpacked';

    public static function emitCallUnpackOperandCheck(Context $context, Variable $operand): void
    {
        TypeErrorRaise::emitBranchOrAbortOnFailure(
            $context,
            self::isArrayValue($context, $operand),
            'call_unpack_non_array',
            self::callUnpackNonArrayMessage($context, $operand)
        );
    }

    /**
     * ZEND_SEND_UNPACK TypeError text — PHP 8.4+ appends {@code , <type> given} (#30023).
     */
    public static function callUnpackNonArrayMessage(Context $context, Variable $operand): string
    {
        $message = self::CALL_UNPACK_NON_ARRAY_MESSAGE;
        if (!\PHPCompiler\CompilerVersion::supportsUnpackTypeErrorGivenSuffix()) {
            return $message;
        }

        return $message.', '.JitOperandTypeLabel::givenLabel($context, $operand).' given';
    }

    /**
     * ADD_ARRAY_UNPACK Error/TypeError text — PHP 8.4+ appends {@code , <type> given} (#30055).
     */
    public static function arraySpreadNonTraversableMessage(Context $context, Variable $operand): string
    {
        $message = ArraySpread::NON_TRAVERSABLE_MESSAGE;
        if (!\PHPCompiler\CompilerVersion::supportsUnpackTypeErrorGivenSuffix()) {
            return $message;
        }

        return $message.', '.JitOperandTypeLabel::givenLabel($context, $operand).' given';
    }

    public static function emitCheck(Context $context, Variable $array): void
    {
        TypeErrorRaise::emitBranchOrAbortOnFailure(
            $context,
            JitArrayIsList::invoke($context, $array),
            'list_unpack',
            self::TYPE_ERROR_MESSAGE
        );
    }

    /**
     * Guarded `[]` / list() destructuring (#4325, #4308, #10486, #21910, #25096).
     *
     * @return bool true when assign-path opcodes should compile as unreachable stubs
     */
    public static function emitGuardedListUnpackCheck(
        Context $context,
        Variable $array,
        \PHPLLVM\BasicBlock $branchBlock,
        \PHPLLVM\BasicBlock $mergeEntry,
        ?Operand $arrayOp = null,
        bool $hasByRef = false,
        ?\PHPCompiler\JIT $jit = null
    ): bool {
        if (self::isDefinitelyNonArrayAtCompileTime($context, $array, $arrayOp)) {
            $context->builder->positionAtEnd($branchBlock);
            if (Variable::TYPE_OBJECT === $array->type) {
                self::emitObjectAsArrayListError($context, $array, $arrayOp, $jit);
                self::finishObjectListErrorBlock($context, $mergeEntry);

                return true;
            }
            if ($hasByRef) {
                return false;
            }
            $context->builder->branch($mergeEntry);
            $deadBb = BasicBlockHelper::append($context, 'list_unpack_skip_assign');
            $context->builder->positionAtEnd($deadBb);

            return true;
        }
        $isUnpackable = self::isListDestructUnpackableValue($context, $array, $arrayOp);
        $assignBb = BasicBlockHelper::append($context, 'list_unpack_array_check');
        $nonUnpackableBb = BasicBlockHelper::append($context, 'list_unpack_non_unpackable');
        $context->builder->positionAtEnd($branchBlock);
        $context->builder->branchIf($isUnpackable, $assignBb, $nonUnpackableBb);
        $context->builder->positionAtEnd($nonUnpackableBb);
        self::branchNonUnpackableListRhs($context, $array, $arrayOp, $assignBb, $mergeEntry, $hasByRef, $jit);
        $context->builder->positionAtEnd($assignBb);

        return false;
    }

    private static function branchNonUnpackableListRhs(
        Context $context,
        Variable $array,
        ?Operand $arrayOp,
        \PHPLLVM\BasicBlock $assignBb,
        \PHPLLVM\BasicBlock $mergeEntry,
        bool $hasByRef,
        ?\PHPCompiler\JIT $jit
    ): void {
        if (Variable::TYPE_OBJECT === $array->type) {
            self::emitObjectAsArrayListError($context, $array, $arrayOp, $jit);
            self::finishObjectListErrorBlock($context, $mergeEntry);

            return;
        }
        if (Variable::TYPE_VALUE === $array->type) {
            ListUnpackRuntime::ensureLinked($context);
            $typeByte = ListUnpackRuntime::loadValueBoxTypeByte($context, $array);
            $i8 = $context->getTypeFromString('int8');
            $isObject = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->and(
                    $context->builder->trunc($typeByte, $i8),
                    $i8->constInt(0x7f, false)
                ),
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_OBJECT & 0x7f, false)
            );
            $objBb = BasicBlockHelper::append($context, 'list_unpack_object_err');
            $otherBb = BasicBlockHelper::append($context, 'list_unpack_non_obj');
            $context->builder->branchIf($isObject, $objBb, $otherBb);
            $context->builder->positionAtEnd($objBb);
            // Never fall through to dim fetch for objects — AOT object-dim is incomplete (#25096).
            self::emitObjectAsArrayListError($context, $array, $arrayOp, $jit);
            // Catchable/uncaught already terminated objBb — do not open an empty dead block
            // (unterminated empty BB breaks AOT verify with try/catch).
            $insert = $context->builder->getInsertBlock();
            if (null !== $insert && null === $insert->getTerminator()) {
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }
            $context->builder->positionAtEnd($otherBb);
            $context->builder->branch($hasByRef ? $assignBb : $mergeEntry);

            return;
        }
        $context->builder->branch($hasByRef ? $assignBb : $mergeEntry);
    }

    private static function emitObjectAsArrayListError(
        Context $context,
        Variable $array,
        ?Operand $arrayOp,
        ?\PHPCompiler\JIT $jit
    ): void {
        unset($array);
        $display = self::objectDisplayNameForListError($arrayOp) ?? 'stdClass';
        $message = VmUnset::cannotUseObjectAsArrayMessage($display);
        if (null !== $jit && [] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableClassError($context, 'Error', $message, $jit);

            return;
        }
        // Uncaught: print Zend-shaped fatal + exit(255). Avoid ErrorRaise pending (silent
        // rc=0) and UncaughtThrowPrinter alloc (AOT segfault on this edge) (#25096, #23641).
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::ensureStandaloneBodies($context);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'fprintf',
            $context->context->functionType($i32, true, $i8p, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'exit',
            $context->context->functionType($context->getTypeFromString('void'), false, $i32)
        );
        $stderr = \PHPCompiler\JIT\Builtin\StringTriggerErrorJit::stderrFilePtr($context);
        $line = 'PHP Fatal error:  Uncaught Error: '.$message."\n";
        $context->builder->call(
            $context->lookupFunction('fprintf'),
            $stderr,
            $context->builder->pointerCast($context->constantFromString('%s'), $i8p),
            $context->builder->pointerCast($context->constantFromString($line), $i8p)
        );
        $context->builder->call(
            $context->lookupFunction('exit'),
            $i32->constInt(255, false)
        );
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
    }

    /**
     * Park builder on a dead block after object Error so assign/jump stubs stay valid.
     * Never returnVoid after UncaughtThrowPrinter — that reattached main epilogue as
     * silent rc=0 (#23641 / #25096).
     */
    private static function finishObjectListErrorBlock(
        Context $context,
        \PHPLLVM\BasicBlock $mergeEntry
    ): void {
        unset($mergeEntry);
        $deadBb = BasicBlockHelper::append($context, 'list_unpack_object_err_dead');
        $context->builder->positionAtEnd($deadBb);
    }

    private static function objectDisplayNameForListError(?Operand $arrayOp): ?string
    {
        if (null === $arrayOp || null === $arrayOp->type || Type::TYPE_OBJECT !== $arrayOp->type->type) {
            return null;
        }
        $userType = $arrayOp->type->userType ?? '';
        if ('' === $userType || 'object' === strtolower(ltrim($userType, '\\'))) {
            return null;
        }

        return ltrim($userType, '\\');
    }

    public static function isDefinitelyNonArrayAtCompileTime(
        Context $context,
        Variable $array,
        ?Operand $arrayOp = null
    ): bool {
        if (self::isDefinitelyArrayAtCompileTime($array)) {
            return false;
        }
        if (ArrayAccessHelper::containerImplementsArrayAccess($context, $array, $arrayOp)) {
            return false;
        }
        if ($array->isNullConstant) {
            return true;
        }
        if (Variable::TYPE_OBJECT === $array->type) {
            return ArrayAccessHelper::isKnownNonArrayAccessObject($context, $array, $arrayOp);
        }

        return Variable::TYPE_NULL === $array->type
            || Variable::TYPE_NATIVE_BOOL === $array->type
            || Variable::TYPE_NATIVE_LONG === $array->type
            || Variable::TYPE_NATIVE_DOUBLE === $array->type
            || Variable::TYPE_STRING === $array->type;
    }

    public static function isListDestructUnpackableValue(
        Context $context,
        Variable $var,
        ?Operand $varOp = null
    ): \PHPLLVM\Value {
        if (ArrayAccessHelper::containerImplementsArrayAccess($context, $var, $varOp)) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(1, false);
        }
        if (ArrayAccessHelper::isKnownNonArrayAccessObject($context, $var, $varOp)) {
            return self::isArrayValue($context, $var);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            ListUnpackRuntime::ensureLinked($context);
            $typeByte = ListUnpackRuntime::loadValueBoxTypeByte($context, $var);
            $i1 = $context->getTypeFromString('int1');

            return ListUnpackRuntime::callValueBoxIsListDestructUnpackable(
                $context,
                $typeByte,
                $i1->constInt(0, false)
            );
        }

        return self::isArrayValue($context, $var);
    }

    public static function isDefinitelyArrayAtCompileTime(Variable $array): bool
    {
        if (!empty($array->valueBoxHashtable)) {
            return true;
        }

        return Variable::TYPE_HASHTABLE === $array->type
            || 0 !== ($array->type & Variable::IS_NATIVE_ARRAY);
    }

    public static function emitIsListBranchOrFail(Context $context, Variable $array): void
    {
        if (self::isDefinitelyNonArrayAtCompileTime($context, $array)) {
            return;
        }
        TypeErrorRaise::emitBranchOrAbortOnFailure(
            $context,
            JitArrayIsList::invoke($context, $array),
            'list_unpack',
            self::TYPE_ERROR_MESSAGE,
            'assign'
        );
    }

    public static function isArrayValue(Context $context, Variable $var): \PHPLLVM\Value
    {
        if (
            Variable::TYPE_HASHTABLE === $var->type
            || ($var->type & Variable::IS_NATIVE_ARRAY)
            || !empty($var->valueBoxHashtable)
        ) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(1, false);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            ListUnpackRuntime::ensureLinked($context);

            return ListUnpackRuntime::callValueBoxIsArray(
                $context,
                ListUnpackRuntime::loadValueBoxTypeByte($context, $var)
            );
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(0, false);
    }
}
