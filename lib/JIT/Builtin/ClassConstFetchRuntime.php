<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\ClassConstFetchHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReadonlyBridge;
use PHPCompiler\JIT\Variable;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT dynamic class const fetch runtime lowering split out of {@see ClassConstFetchHelper} (#10200).
 *
 * This keeps the high-level emit API small while preserving current lowering behavior.
 */
final class ClassConstFetchRuntime
{
    /**
     * Lower dynamic class constant fetch with runtime class id and runtime name.
     *
     * @return Variable TYPE_VALUE box
     */
    public static function fetchDynamicByClassIdValue(
        Object_ $objectType,
        Value $classIdVal,
        Variable $nameVar,
        Operand $classOp,
        ?Block $block = null,
        ?\PHPCompiler\JIT $jit = null
    ): Variable {
        $context = $objectType->jitContext();
        self::ensureStrCaseCmp($context);
        ReadonlyBridge::ensureLinked($context);

        $nativeName = JitStringArg::lower($context, $nameVar, 'class constant name');
        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock('class_const_dyn_merge');
        $fail = $fn->appendBasicBlock('class_const_dyn_fail');

        $classPseudo = $context->builder->load($context->constantStringFromString('class'));
        $context->builder->positionAtEnd($entry);
        $isClass = $context->builder->call(
            $context->lookupFunction('strcasecmp'),
            self::stringDataPtr($context, $nativeName),
            self::stringDataPtr($context, $classPseudo)
        );
        $i32 = $context->getTypeFromString('int32');
        $classMatch = $fn->appendBasicBlock('class_const_dyn_class');
        $constChain = $fn->appendBasicBlock('class_const_dyn_chain');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $isClass, $i32->constInt(0, false)),
            $classMatch,
            $constChain
        );

        $context->builder->positionAtEnd($classMatch);
        $classNameStr = ClassConstFetchHelper::emitClassNameStringFromClassId($objectType, $classIdVal);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $resultSlot),
            $classNameStr
        );
        $context->builder->branch($merge);

        $checkBlock = $constChain;
        $context->builder->positionAtEnd($constChain);
        foreach ($objectType->allClassNamesById() as $id => $_) {
            $constants = $objectType->classConstantsForId($id);
            foreach ($constants as [$constKey, $entry]) {
                $nextCheck = $fn->appendBasicBlock('class_const_dyn_try_'.$id.'_'.$constKey);
                $matchBlock = $fn->appendBasicBlock('class_const_dyn_match_'.$id.'_'.$constKey);
                $context->builder->positionAtEnd($checkBlock);
                $expectedId = $context->constantFromInteger($id, 'int64');
                $isId = $context->builder->icmp(Builder::INT_EQ, $classIdVal, $expectedId);
                $keyGlobal = $context->builder->load($context->constantStringFromString($constKey));
                $cmp = $context->builder->call(
                    $context->lookupFunction('strcasecmp'),
                    self::stringDataPtr($context, $nativeName),
                    self::stringDataPtr($context, $keyGlobal)
                );
                $isName = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
                $isMatch = $context->builder->and($isId, $isName);
                $context->builder->branchIf($isMatch, $matchBlock, $nextCheck);

                $context->builder->positionAtEnd($matchBlock);
                if ($objectType->isTraitClass(strtolower(ltrim($objectType->classNameForId($id), '\\')))
                    && !$objectType->isInTraitMethodScopeForTraitId($id, $block)) {
                    $classLabel = $objectType->classNameForId($id);
                    ErrorRaise::ensureLinked($context);
                    ErrorRaise::emitRaise(
                        $context,
                        "Cannot access trait constant {$classLabel}::* directly"
                    );
                    $context->builder->branch($merge);
                } else {
                    if (null !== $jit) {
                        \PHPCompiler\JIT\ClassConstVisibilityJitGuard::emitBeforeFetch(
                            $objectType,
                            $jit,
                            $block,
                            $id,
                            $objectType->classConstDisplayName($id, $constKey)
                        );
                        if ($objectType->isEnumClassId($id)) {
                            \PHPCompiler\JIT\BackedEnumDuplicateJitGuard::emitBeforeEnumCaseFetch(
                                $objectType,
                                $jit,
                                $block,
                                $id
                            );
                        }
                    }
                    if ($objectType->isEnumClassId($id)) {
                        // Enum case singleton lives in module global; write via helper.
                        ClassConstFetchHelper::writeEnumCaseConstEntryForRuntime(
                            $objectType,
                            $context,
                            $resultSlot,
                            $id,
                            $constKey
                        );
                    } else {
                        ClassConstFetchHelper::writeConstEntryForRuntime($context, $resultSlot, $entry);
                    }
                    $context->builder->branch($merge);
                }
                $checkBlock = $nextCheck;
            }
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($fail);
        $displayClass = self::displayClassName($objectType, -1, $classOp);
        $message = "Undefined class constant {$displayClass}::*";
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            ClassConstFetchHelper::messageDataPtrForRuntime($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($merge);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $resultSlot
        );
    }

    private static function ensureStrCaseCmp(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        try {
            $context->lookupFunction('strcasecmp');
        } catch (\Throwable) {
            $fn = $context->module->addFunction('strcasecmp', $ft);
            $context->registerFunction('strcasecmp', $fn);
        }
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strPtr, $map['value']);
    }

    private static function displayClassName(Object_ $objectType, int $classId, Operand $classOp): string
    {
        if ($classOp instanceof Operand\Literal) {
            return $classOp->value;
        }
        if ($classId < 0) {
            return '*';
        }

        return $objectType->classNameForId($classId);
    }
}

