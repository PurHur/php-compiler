<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\BackedEnumFromRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT lowering for BackedEnum::from() / ::tryFrom() (#4053, #10273).
 *
 * SSOT: {@see \PHPCompiler\VM\EnumFromJitHelper}, {@see \PHPCompiler\VM\BackedEnum}
 * php-src: Zend/zend_enum.c — zend_enum_from_case(), zend_try_enum_from_case()
 */
final class BackedEnumFromJit
{
    public static function emitFromFunction(
        Context $context,
        ObjectBuiltin $object,
        int $classId,
        string $className,
        string $backedType,
        bool $isTry
    ): void {
        $caseKeys = $object->enumCaseOrderForClass($classId);
        if ([] === $caseKeys) {
            return;
        }

        $valuePtrTy = $context->getTypeFromString('__value__*');
        $fnType = $context->context->functionType($valuePtrTy, false, $valuePtrTy);
        $method = $isTry ? 'tryfrom' : 'from';
        $funcName = strtolower(ltrim($className, '\\')).'::'.$method;
        if ($context->functionIsRegistered($funcName)) {
            return;
        }

        BackedEnumFromRuntime::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        $fn = $context->module->addFunction($funcName, $fnType);
        $lc = strtolower($funcName);
        $context->functions[$lc] = $fn;
        $context->functionProxies[$lc] = new Call\Native($fn, $funcName, [$valuePtrTy]);

        $object->defineMethodVisibility(
            $classId,
            $method,
            \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC,
            $isTry ? 'tryFrom' : 'from'
        );

        $restore = $context->builder->getInsertBlock();
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $arg = $fn->getParam(0);

        if ('string' === $backedType) {
            self::emitStringBackedBody($context, $object, $classId, $className, $caseKeys, $arg, $isTry);
        } elseif ('int' === $backedType) {
            self::emitIntBackedBody($context, $object, $classId, $className, $caseKeys, $arg, $isTry);
        } else {
            throw new \LogicException('Unsupported enum backing type for JIT from(): '.$backedType);
        }

        if (null !== $restore) {
            $context->builder->positionAtEnd($restore);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param list<string> $caseKeys
     */
    private static function emitStringBackedBody(
        Context $context,
        ObjectBuiltin $object,
        int $classId,
        string $className,
        array $caseKeys,
        Value $arg,
        bool $isTry
    ): void {
        $fn = BasicBlockHelper::parentFunction($context);
        $noMatchBlock = $fn->appendBasicBlock('enum_from_string_no_match');
        $normalized = BackedEnumFromRuntime::normalizeStringBacking($context, $arg);
        $packed = self::packStringBackings($object, $classId, $caseKeys);
        $matchIndex = BackedEnumFromRuntime::matchStringBacking(
            $context,
            $normalized,
            $packed,
            \count($caseKeys)
        );
        $i64 = $context->getTypeFromString('int64');
        $lastIdx = \count($caseKeys) - 1;
        for ($idx = 0; $idx <= $lastIdx; ++$idx) {
            $matchBlock = $fn->appendBasicBlock('enum_from_string_match_'.$idx);
            $nextBlock = $idx === $lastIdx ? $noMatchBlock : $fn->appendBasicBlock('enum_from_string_next_'.$idx);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $matchIndex,
                    $i64->constInt($idx, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            $context->builder->returnValue(
                self::returnEnumCaseValue($context, $object, $classId, $caseKeys[$idx])
            );
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($noMatchBlock);
        if ($isTry) {
            $context->builder->returnValue(self::returnNullValue($context));
        } else {
            BackedEnumFromRuntime::emitStringValueError($context, $className, $normalized);
            $context->builder->returnValue(self::returnNullValue($context));
        }
    }

    /**
     * @param list<string> $caseKeys
     */
    private static function emitIntBackedBody(
        Context $context,
        ObjectBuiltin $object,
        int $classId,
        string $className,
        array $caseKeys,
        Value $arg,
        bool $isTry
    ): void {
        $fn = BasicBlockHelper::parentFunction($context);
        $noMatchBlock = $fn->appendBasicBlock('enum_from_int_no_match');
        $normalized = BackedEnumFromRuntime::normalizeIntBacking($context, $className, $arg);
        $csv = self::packIntBackings($object, $classId, $caseKeys);
        $matchIndex = BackedEnumFromRuntime::matchIntBacking($context, $normalized, $csv);
        $i64 = $context->getTypeFromString('int64');
        $lastIdx = \count($caseKeys) - 1;
        for ($idx = 0; $idx <= $lastIdx; ++$idx) {
            $matchBlock = $fn->appendBasicBlock('enum_from_int_match_'.$idx);
            $nextBlock = $idx === $lastIdx ? $noMatchBlock : $fn->appendBasicBlock('enum_from_int_next_'.$idx);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $matchIndex,
                    $i64->constInt($idx, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            $context->builder->returnValue(
                self::returnEnumCaseValue($context, $object, $classId, $caseKeys[$idx])
            );
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($noMatchBlock);
        if ($isTry) {
            $context->builder->returnValue(self::returnNullValue($context));
        } else {
            BackedEnumFromRuntime::emitIntValueError($context, $className, $normalized);
            $context->builder->returnValue(self::returnNullValue($context));
        }
    }

    /**
     * @param list<string> $caseKeys
     */
    private static function packStringBackings(ObjectBuiltin $object, int $classId, array $caseKeys): string
    {
        $parts = [];
        foreach ($caseKeys as $caseKey) {
            $parts[] = (string) $object->enumCaseBackingScalarForCase($classId, $caseKey);
        }

        return implode("\0", $parts);
    }

    /**
     * @param list<string> $caseKeys
     */
    private static function packIntBackings(ObjectBuiltin $object, int $classId, array $caseKeys): string
    {
        $parts = [];
        foreach ($caseKeys as $caseKey) {
            $parts[] = (string) (int) $object->enumCaseBackingScalarForCase($classId, $caseKey);
        }

        return implode(',', $parts);
    }

    private static function returnEnumCaseValue(Context $context, ObjectBuiltin $object, int $classId, string $caseKey): Value
    {
        $caseVar = $object->jitEnumCaseFromBacking($classId, $caseKey);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $objPtr = Variable::KIND_VALUE === $caseVar->kind
            ? $caseVar->value
            : $context->builder->load($caseVar->value);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $objPtr
        );

        return $ptr;
    }

    private static function returnNullValue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return $ptr;
    }
}
