<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
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
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireArgCountRange($frame, 'str_ireplace', 3, 4);
        $argc = \count($frame->calledArgs);
        $hasCount = $argc >= 4;
        // Z_PARAM_STR_OR_ARR — null TypeError on PROFILE=8.4 (#20173, #18914; php-src string.c).
        $searchVar = VmPreg::resolveStringOrArraySubject(
            $frame,
            $frame->calledArgs[0],
            'str_ireplace',
            0,
            'search'
        );
        $replaceVar = VmPreg::resolveStringOrArraySubject(
            $frame,
            $frame->calledArgs[1],
            'str_ireplace',
            1,
            'replace'
        );
        $subjectVar = VmPreg::resolveStringOrArraySubject(
            $frame,
            $frame->calledArgs[2],
            'str_ireplace',
            2,
            'subject'
        );
        // php-src string.c php_str_replace_common — array $replace only when $search is array (#22827).
        self::rejectStringSearchWithArrayReplace($searchVar, $replaceVar, 'str_ireplace');

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
            // php-src php_str_replace_array: convert_to_string per array subject (#27165).
            $elemCount = 0;
            $replaced = self::replaceWithNeedles(
                $searchNeedles,
                $replaceOperand,
                $value->resolveIndirect()->toString(null, $frame),
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
        if (!$this->requireArgCountRangeJit($context, $args, 'str_ireplace', 3, 4)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21189/#21198).
        if (JitInternalStrictArg::rejectNullStringOrArray($context, $args[0], 'str_ireplace', 'search', 1, false)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        if (JitInternalStrictArg::rejectNullStringOrArray($context, $args[1], 'str_ireplace', 'replace', 2, false)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        if (JitInternalStrictArg::rejectNullStringOrArray($context, $args[2], 'str_ireplace', 'subject', 3, false)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        JitPregSubject::requireStringOrArray($context, $args[2], 'str_ireplace', 2, 'subject');
        // php-src string.c — string $search + array $replace → TypeError (#22827 / re-#11056).
        if (JitStrReplaceSearchReplaceGuard::rejectStringSearchWithArrayReplace(
            $context,
            $args[0],
            $args[1],
            'str_ireplace'
        )) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $countSlot = self::jitCountSlot($context, 4 === $argc);
        // #23912 — AOT TYPE_VALUE string locals must not take the array $subject path
        // (ensureHashtablePointer overwrites the string box → echo "Array").
        $subjectIsStringish = JitPregSubject::isStringOrCoercibleNullSubject($args[2])
            || (
                JITVariable::TYPE_VALUE === $args[2]->type
                && !JitStrReplaceSubject::isKnownArray($args[2])
            );
        if ($subjectIsStringish) {
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
                    JitStringBuiltinArg::lower($context, $args[2], 'str_ireplace', 2, 'subject', 'array|string', null, false),
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
        // Prefer already-resolved Z_PARAM_STR_OR_ARR value (#20173) — avoid re-coercing the raw arg.
        if (Variable::TYPE_STRING === $var->type) {
            return [$var->toString()];
        }
        if (Variable::TYPE_NULL === $var->type) {
            return [VmString::coerceStringBuiltinArg($arg, $function, $argIndex, $paramName, 'array|string')];
        }

        $needles = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $value]) {
            // Element convert_to_string — not Z_PARAM_STR (#29309).
            $needles[] = VmString::coerceStrReplaceArrayElement($value);
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
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return VmString::coerceStringBuiltinArg($arg, $function, $argIndex, $paramName, 'array|string');
        }

        $values = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $value]) {
            // Element convert_to_string — not Z_PARAM_STR (#29309).
            $values[] = VmString::coerceStrReplaceArrayElement($value);
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

    /** @throws \TypeError */
    private static function rejectStringSearchWithArrayReplace(
        Variable $searchVar,
        Variable $replaceVar,
        string $function
    ): void {
        if (Variable::TYPE_ARRAY !== $searchVar->type && Variable::TYPE_ARRAY === $replaceVar->type) {
            throw new \TypeError(
                $function.'(): Argument #2 ($replace) must be of type string when argument #1 ($search) is a string'
            );
        }
    }
}
