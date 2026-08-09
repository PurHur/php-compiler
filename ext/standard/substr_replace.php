<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\RuntimeStrictness;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** substr_replace() — replace substring slice (php-src ext/standard/string.c; #3356, #4057). */
final class substr_replace extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#25407).
        $this->requireArgCountRange($frame, 'substr_replace', 3, 4);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $stringVar = VmPreg::resolveStringOrArraySubject(
            $frame,
            $frame->calledArgs[0],
            'substr_replace',
            0,
            'string'
        );
        $replaceVar = self::requireStringOrArrayReplace($frame->calledArgs[1]);
        $offsetVar = $frame->calledArgs[2]->resolveIndirect();
        $hasLength = 4 === $argc;
        $lengthVar = $hasLength ? $frame->calledArgs[3] : null;

        if (Variable::TYPE_STRING === $stringVar->type) {
            if (Variable::TYPE_ARRAY === $offsetVar->type) {
                throw new \TypeError(
                    'substr_replace(): Argument #3 ($offset) cannot be an array when working on a single string'
                );
            }
            if ($hasLength && Variable::TYPE_ARRAY === $lengthVar->resolveIndirect()->type) {
                throw new \TypeError(
                    'substr_replace(): Argument #4 ($length) cannot be an array when working on a single string'
                );
            }
            $replace = self::resolveScalarReplace($frame->calledArgs[1], $replaceVar);
            $offsetInt = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'substr_replace', 3, 'offset');
            $length = self::resolveScalarLength($lengthVar, $stringVar->toString());
            $frame->returnVar->string(VmString::substr_replace(
                $stringVar->toString(),
                $replace,
                $offsetInt,
                $length
            ));

            return;
        }

        $frame->returnVar->array(self::replaceOnStringArray(
            $stringVar,
            $replaceVar,
            $offsetVar,
            $lengthVar,
            $hasLength
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'substr_replace', 3, 4)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('substr_replace() offset must be an integer in this compiler build');
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $lengthVal = $i64->constInt(0, false);
        $hasLength = $i32->constInt(0, false);
        if (4 === $argc) {
            if (JITVariable::TYPE_NATIVE_LONG === $args[3]->type) {
                $lengthVal = $this->jitLong($context, $args[3], 'substr_replace() length');
                $hasLength = $i32->constInt(1, false);
            } elseif (JITVariable::TYPE_VALUE === $args[3]->type) {
                if (!$args[3]->isNullConstant) {
                    throw new \LogicException('substr_replace() length must be an integer or literal null in this compiler build');
                }
                $lengthVal = $i64->constInt(0, false);
                $hasLength = $i32->constInt(0, false);
            } else {
                throw new \LogicException('substr_replace() length must be an integer or null in this compiler build');
            }
        }

        JitPregSubject::requireStringOrArray($context, $args[0], 'substr_replace', 0, 'string');
        // #23912 peer — AOT TYPE_VALUE string locals must not take the array $string path.
        $stringIsStringish = JitPregSubject::isStringOrCoercibleNullSubject($args[0])
            || (
                JITVariable::TYPE_VALUE === $args[0]->type
                && !JitStrReplaceSubject::isKnownArray($args[0])
            );
        $offset = $this->jitLong($context, $args[2], 'substr_replace() offset');
        // php-src string.c: scalar $string + array $replace → first element via convert_to_string (#29309).
        $replace = self::jitScalarReplaceArg($context, $args[1]);
        if ($stringIsStringish) {
            return JitSubstrReplace::replace(
                $context,
                self::jitStringArg($context, $args[0], 0, 'string', 'array|string'),
                $replace,
                $offset,
                $lengthVal,
                $hasLength
            );
        }

        if (JitStrReplaceSubject::isKnownArray($args[1])) {
            throw new \LogicException(
                'substr_replace() array $replace with array $string is not supported in this compiler build'
            );
        }

        return JitSubstrReplaceArray::invoke(
            $context,
            $args[0],
            $replace,
            $offset,
            $lengthVal,
            $hasLength
        );
    }

    /**
     * Scalar-string path $replace — string or first compile-time array element (#29309).
     */
    private static function jitScalarReplaceArg(Context $context, JITVariable $arg): Value
    {
        // Compile-time array literal (native or value-box hashtable) — first element (#29309).
        if (\is_array($arg->compileTimeArray) && JitStrReplaceSubject::isKnownArray($arg)) {
            if ([] === $arg->compileTimeArray) {
                return $context->builder->load($context->constantStringFromString(''));
            }
            $first = $arg->compileTimeArray[\array_key_first($arg->compileTimeArray)];
            if (null === $first) {
                // convert_to_string on null → "" without parameter DEP.
                return $context->builder->load($context->constantStringFromString(''));
            }
            if (\is_string($first) || \is_int($first) || \is_float($first) || \is_bool($first)) {
                return $context->builder->load($context->constantStringFromString((string) $first));
            }
            throw new \LogicException(
                'substr_replace() array $replace element must be string-coercible at compile time in this compiler build'
            );
        }
        if (JitStrReplaceSubject::isKnownArray($arg)) {
            throw new \LogicException(
                'substr_replace() runtime array $replace is not supported in this compiler build'
            );
        }

        return JitStringBuiltinArg::lower($context, $arg, 'substr_replace', 1, 'replace', 'array|string');
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'substr_replace',
                $argIndex,
                $paramName,
                $expectedType
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'substr_replace',
            $argIndex,
            $paramName,
            $expectedType
        );
    }

    /**
     * @throws \TypeError
     */
    private static function requireStringOrArrayReplace(Variable $var): Variable
    {
        $var = $var->resolveIndirect();
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                'substr_replace(): Argument #2 ($replace) must be of type array|string, %s given',
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (
            Variable::TYPE_STRING === $var->type
            || Variable::TYPE_ARRAY === $var->type
            || Variable::TYPE_NULL === $var->type
        ) {
            return $var;
        }

        throw new \TypeError(\sprintf(
            'substr_replace(): Argument #2 ($replace) must be of type array|string, %s given',
            self::replaceArgTypeLabel($var)
        ));
    }

    private static function replaceArgTypeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }

    /**
     * Scalar-string path: first replace element when $replace is an array (php-src string.c).
     */
    private static function resolveScalarReplace(Variable $replaceArg, Variable $replaceVar): string
    {
        if (Variable::TYPE_STRING === $replaceVar->type || Variable::TYPE_NULL === $replaceVar->type) {
            return VmString::coerceStringBuiltinArg($replaceArg, 'substr_replace', 1, 'replace', 'array|string');
        }

        foreach ($replaceVar->toArray()->iterateKeyed(true) as [, $value]) {
            // Element convert_to_string — not Z_PARAM_STR (#29309).
            return VmString::coerceStrReplaceArrayElement($value);
        }

        return '';
    }

    /**
     * @return int|null null means "replace to end of string"
     */
    private static function resolveScalarLength(?Variable $lengthArg, string $string): ?int
    {
        if (null === $lengthArg) {
            return null;
        }
        $lengthVar = $lengthArg->resolveIndirect();
        if (Variable::TYPE_NULL === $lengthVar->type) {
            return null;
        }

        return VmMath::parseIntBuiltinArg($lengthArg, 'substr_replace', 4, 'length');
    }

    /**
     * Array $string path — php-src string.c PHP_FUNCTION(substr_replace) array branch (#4057).
     */
    private static function replaceOnStringArray(
        Variable $stringVar,
        Variable $replaceVar,
        Variable $offsetVar,
        ?Variable $lengthArg,
        bool $hasLength
    ): HashTable {
        $offsetIsArray = Variable::TYPE_ARRAY === $offsetVar->type;
        $lengthIsArray = $hasLength && Variable::TYPE_ARRAY === $lengthArg->resolveIndirect()->type;
        $replaceIsArray = Variable::TYPE_ARRAY === $replaceVar->type;
        $scalarOffset = $offsetIsArray
            ? null
            : VmMath::parseIntBuiltinArg($offsetVar, 'substr_replace', 3, 'offset');
        $scalarLength = null;
        $lengthIsNull = !$hasLength;
        if ($hasLength && !$lengthIsArray) {
            $lengthResolved = $lengthArg->resolveIndirect();
            if (Variable::TYPE_NULL !== $lengthResolved->type) {
                $scalarLength = VmMath::parseIntBuiltinArg($lengthArg, 'substr_replace', 4, 'length');
                $lengthIsNull = false;
            }
        }
        $scalarReplace = $replaceIsArray
            ? null
            : VmString::coerceStringBuiltinArg($replaceVar, 'substr_replace', 1, 'replace', 'array|string');

        /** @var list<Variable> $offsetValues */
        $offsetValues = $offsetIsArray ? self::arrayArgValues($offsetVar) : [];
        /** @var list<Variable> $lengthValues */
        $lengthValues = $lengthIsArray ? self::arrayArgValues($lengthArg->resolveIndirect()) : [];
        /** @var list<Variable> $replaceValues */
        $replaceValues = $replaceIsArray ? self::arrayArgValues($replaceVar) : [];

        $offsetIdx = 0;
        $lengthIdx = 0;
        $replaceIdx = 0;
        $out = new HashTable();

        foreach ($stringVar->toArray()->iterateKeyed(true) as [$key, $value]) {
            $orig = VmString::coerceStringBuiltinArg($value, 'substr_replace', 0, 'string');
            if ($offsetIsArray) {
                $offset = self::nextArrayInt(
                    $offsetValues,
                    $offsetIdx,
                    0,
                    'substr_replace',
                    3,
                    'offset'
                );
            } else {
                $offset = $scalarOffset;
            }
            if ($lengthIsArray) {
                $length = self::nextArrayInt(
                    $lengthValues,
                    $lengthIdx,
                    VmString::byteLength($orig),
                    'substr_replace',
                    4,
                    'length'
                );
            } elseif ($lengthIsNull) {
                $length = null;
            } else {
                $length = $scalarLength;
            }
            if ($replaceIsArray) {
                $replace = self::nextArrayString(
                    $replaceValues,
                    $replaceIdx,
                    '',
                    'substr_replace',
                    2,
                    'replace'
                );
            } else {
                $replace = $scalarReplace;
            }

            $replaced = VmString::substr_replace($orig, $replace, $offset, $length);
            $outVal = new Variable();
            $outVal->string($replaced);
            array_map::appendKeyedCopy($out, $key, $outVal);
        }

        return $out;
    }

    /** @return list<Variable> */
    private static function arrayArgValues(Variable $var): array
    {
        $values = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $value]) {
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param list<Variable> $values
     */
    private static function nextArrayInt(
        array $values,
        int &$index,
        int $defaultWhenExhausted,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        if ($index >= \count($values)) {
            return $defaultWhenExhausted;
        }

        return VmMath::parseIntBuiltinArg($values[$index++], $function, $argIndex, $paramName);
    }

    /**
     * @param list<Variable> $values
     */
    private static function nextArrayString(
        array $values,
        int &$index,
        string $defaultWhenExhausted,
        string $function,
        int $argIndex,
        string $paramName
    ): string {
        if ($index >= \count($values)) {
            return $defaultWhenExhausted;
        }

        // Array element — convert_to_string, not Z_PARAM_STR (#29309).
        return VmString::coerceStrReplaceArrayElement($values[$index++]);
    }
}
