<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\RuntimeStrictness;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** str_ireplace() — case-insensitive str_replace for strings (VM + JIT/AOT via JitStringSearch). */
final class str_ireplace extends Internal
{
    public function __construct()
    {
        parent::__construct('str_ireplace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('str_ireplace() requires 3 or 4 arguments in this compiler build');
        }
        $hasCount = $argc >= 4;
        $searchVar = self::requireStringOrArrayReplace($frame, $frame->calledArgs[0], 'str_ireplace', 0, 'search');
        $replaceVar = self::requireStringOrArrayReplace($frame, $frame->calledArgs[1], 'str_ireplace', 1, 'replace');
        $subjectVar = VmPreg::resolveStringOrArraySubject(
            $frame,
            $frame->calledArgs[2],
            'str_ireplace',
            2,
            'subject'
        );

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $count = 0;
            $result = self::replaceSubjectString(
                $searchVar,
                $replaceVar,
                $frame->calledArgs[0],
                $frame->calledArgs[1],
                $subjectVar->toString(),
                $count
            );
            if ($hasCount) {
                $frame->calledArgs[3]->resolveIndirect()->int($count);
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->string($result);
            }

            return;
        }

        $searchNeedles = self::coerceNeedleList($searchVar, $frame->calledArgs[0], 'str_ireplace', 0, 'search');
        $replaceOperand = self::coerceReplaceOperand($replaceVar, $frame->calledArgs[1], 'str_ireplace', 1, 'replace');
        $ht = new HashTable();
        $totalCount = 0;
        foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_STRING !== $value->type) {
                throw new \LogicException(
                    'str_ireplace() array subject values must be strings in this compiler build'
                );
            }
            $elemCount = 0;
            $replaced = self::replaceWithNeedles(
                $searchNeedles,
                $replaceOperand,
                $value->toString(),
                $elemCount
            );
            $totalCount += $elemCount;
            $keyVar = new Variable();
            if (Variable::TYPE_INTEGER === $key->type) {
                $keyVar->int($key->toInt());
            } else {
                $keyVar->string($key->toString());
            }
            $outVal = new Variable();
            $outVal->string($replaced);
            array_map::appendKeyedCopy($ht, $keyVar, $outVal);
        }
        if ($hasCount) {
            $frame->calledArgs[3]->resolveIndirect()->int($totalCount);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($ht);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('str_ireplace() requires 3 or 4 arguments in this compiler build');
        }

        JitInternalStrictArg::rejectNullStringOrArray($context, $args[2], 'str_ireplace', 'subject', 3);
        JitPregSubject::requireStringOrArray($context, $args[2], 'str_ireplace', 2, 'subject');
        $countSlot = self::jitCountSlot($context, 4 === $argc);
        if (JitPregSubject::isStringOrCoercibleNullSubject($args[2])) {
            if (self::isArrayReplaceArg($args[0]) || self::isArrayReplaceArg($args[1])) {
                $result = JitStrIreplaceMulti::replace(
                    $context,
                    $args[0],
                    $args[1],
                    $args[2],
                    $countSlot
                );
            } else {
                $result = JitStrIreplace::replace(
                    $context,
                    JitStringBuiltinArg::lower($context, $args[0], 'str_ireplace', 0, 'search', 'array|string'),
                    JitStringBuiltinArg::lower($context, $args[1], 'str_ireplace', 1, 'replace', 'array|string'),
                    JitStringBuiltinArg::lower($context, $args[2], 'str_ireplace', 2, 'subject', 'array|string'),
                    $countSlot
                );
            }
        } else {
            if (self::isArrayReplaceArg($args[0]) || self::isArrayReplaceArg($args[1])) {
                throw new \LogicException(
                    'str_ireplace() array $search/$replace with array $subject is not supported in this compiler build'
                );
            }
            $result = JitStrReplaceArray::invoke(
                $context,
                JitStringBuiltinArg::lower($context, $args[0], 'str_ireplace', 0, 'search', 'array|string'),
                JitStringBuiltinArg::lower($context, $args[1], 'str_ireplace', 1, 'replace', 'array|string'),
                $args[2],
                true,
                $countSlot
            );
        }
        if (4 === $argc) {
            JitValueBox::writeLong(
                $context,
                JitValueBox::valuePtrFromVariable($context, $args[3]),
                $context->builder->load($countSlot)
            );
        }

        return $result;
    }

    private static function replaceSubjectString(
        Variable $searchVar,
        Variable $replaceVar,
        Variable $searchArg,
        Variable $replaceArg,
        string $subject,
        int &$count
    ): string {
        $searchNeedles = self::coerceNeedleList($searchVar, $searchArg, 'str_ireplace', 0, 'search');
        $replaceOperand = self::coerceReplaceOperand($replaceVar, $replaceArg, 'str_ireplace', 1, 'replace');

        return self::replaceWithNeedles($searchNeedles, $replaceOperand, $subject, $count);
    }

    /**
     * @param list<string>        $searchNeedles
     * @param list<string>|string $replaceOperand
     */
    private static function replaceWithNeedles(
        array $searchNeedles,
        array|string $replaceOperand,
        string $subject,
        int &$count
    ): string {
        if (1 === \count($searchNeedles) && \is_string($replaceOperand)) {
            return VmString::strIreplace($searchNeedles[0], $replaceOperand, $subject, $count);
        }

        return VmString::strIreplaceMulti($searchNeedles, $replaceOperand, $subject, $count);
    }

    /**
     * @return list<string>
     */
    private static function coerceNeedleList(
        Variable $var,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): array {
        if (Variable::TYPE_STRING === $var->type || Variable::TYPE_NULL === $var->type) {
            return [VmString::coerceStringBuiltinArg($arg, $function, $argIndex, $paramName)];
        }

        $needles = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $value]) {
            $needles[] = VmString::coerceStringBuiltinArg($value, $function, $argIndex, $paramName);
        }

        return $needles;
    }

    /**
     * @return list<string>|string
     */
    private static function coerceReplaceOperand(
        Variable $var,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): array|string {
        if (Variable::TYPE_STRING === $var->type || Variable::TYPE_NULL === $var->type) {
            return VmString::coerceStringBuiltinArg($arg, $function, $argIndex, $paramName);
        }

        $values = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $value]) {
            $values[] = VmString::coerceStringBuiltinArg($value, $function, $argIndex, $paramName);
        }

        return $values;
    }

    private static function jitCountSlot(Context $context, bool $track): ?Value
    {
        if (!$track) {
            return null;
        }
        $i64 = $context->getTypeFromString('int64');
        $slot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $slot);

        return $slot;
    }

    /**
     * php-src Z_PARAM_STR on str_ireplace() search/replace — null coerces outside strict_types (#11014, ext/standard/string.c).
     */
    private static function requireStringOrArrayReplace(
        Frame $frame,
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): Variable {
        $var = $var->resolveIndirect();
        if (InternalStrictArg::isCallerStrict($frame) && Variable::TYPE_NULL === $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, null given',
                $function,
                $argIndex + 1,
                $paramName
            ));
        }
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING === $var->type
            || Variable::TYPE_ARRAY === $var->type
            || Variable::TYPE_NULL === $var->type
        ) {
            return $var;
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type array|string, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            self::replaceArgTypeLabel($var)
        ));
    }

    private static function isArrayReplaceArg(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return true;
        }

        return false;
    }

    private static function replaceArgTypeLabel(Variable $var): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }

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
}
