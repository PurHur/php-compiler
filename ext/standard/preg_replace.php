<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * preg_replace() — VM via VmPreg; JIT/AOT via __compiler_preg_replace (issue #1176).
 * Optional $limit (4th arg): VM (#3605); JIT/AOT via __compiler_preg_replace (#4765).
 * Array $subject: VM + JIT (#4055, ext/pcre/php_pcre.c php_pcre_replace).
 */
final class preg_replace extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_replace');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/pcre/php_pcre.c — ArgumentCountError (#25407).
        $this->requireArgCountRange($frame, 'preg_replace', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        $patternRaw = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $patternRaw->type) {
            // Zend 8.4: soft-null $pattern — DEP then empty-regex warning + null (#21198).
            if (InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(
                    'preg_replace(): Argument #1 ($pattern) must be of type array|string, null given'
                );
            }
            VmNullStringParamDeprecation::emit($frame, 'preg_replace', 0, 'pattern', 'array|string');
            VmPregFailure::warnEmptyRegularExpression($frame, 'preg_replace');
            $frame->returnVar->null();

            return;
        }
        $patternVar = VmPreg::requireStringOrArrayArg($frame->calledArgs[0], 'preg_replace', 0, 'pattern');
        if (Variable::TYPE_STRING === $patternVar->type && '' === $patternVar->toString()) {
            VmPregFailure::warnEmptyRegularExpression($frame, 'preg_replace');
            $frame->returnVar->null();

            return;
        }
        $replacementVar = VmPreg::resolveStringOrArrayReplacement(
            $frame,
            $frame->calledArgs[1],
            'preg_replace',
            1,
            'replacement'
        );
        $subjectVar = VmPreg::resolveStringOrArraySubject(
            $frame,
            $frame->calledArgs[2],
            'preg_replace',
            2,
            'subject'
        );
        $limit = -1;
        if (isset($frame->calledArgs[3])) {
            // Z_PARAM_LONG $limit — soft-null DEP+coerce on 8.4 (php_pcre.c; #21655).
            $limit = VmMath::parseChrCodepointForFrame($frame, 3, 'preg_replace', 4, 'limit');
        }

        $pattern = self::patternOrReplacementOperand($patternVar, $frame->calledArgs[0], 'preg_replace', 0, 'pattern');
        VmPregFailure::warnPatternCompileFailureOperand($frame, 'preg_replace', $pattern);
        // Use resolved $replacementVar so soft-null DEP is not re-emitted as type "string" (#29722).
        $replacement = self::patternOrReplacementOperand(
            $replacementVar,
            $replacementVar,
            'preg_replace',
            1,
            'replacement'
        );
        // Named count: may skip limit — use isset not argc (#19697).
        $hasCount = isset($frame->calledArgs[4]);
        $count = 0;

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $result = VmPreg::pregReplace($pattern, $replacement, $subjectVar->toString(), $limit, $count);
        } elseif (Variable::TYPE_ARRAY === $subjectVar->type) {
            $host = [];
            foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
                // php-src php_pcre_replace: convert_to_string per array subject (#27164).
                $hostKey = Variable::TYPE_INTEGER === $key->type
                    ? $key->toInt()
                    : $key->toString();
                $host[$hostKey] = $value->resolveIndirect()->toString(null, $frame);
            }
            $result = VmPreg::pregReplace($pattern, $replacement, $host, $limit, $count);
        }

        if ($hasCount) {
            $frame->calledArgs[4]->resolveIndirect()->int($count);
        }

        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        if (\is_string($result)) {
            $frame->returnVar->string($result);

            return;
        }
        $ht = new HashTable();
        foreach ($result as $key => $line) {
            $keyVar = new Variable();
            if (\is_int($key)) {
                $keyVar->int($key);
            } else {
                $keyVar->string((string) $key);
            }
            $value = new Variable();
            $value->string((string) $line);
            array_map::appendKeyedCopy($ht, $keyVar, $value);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // JIT/AOT supports through $limit; $count remains VM (#4765). Zend max is 5 (#25407).
        if (!$this->requireArgCountRangeJit($context, $args, 'preg_replace', 3, 4)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        $limit = 4 === $argc
            ? self::lowerLimit($context, $args[3])
            : $context->getTypeFromString('int64')->constInt(-1, false);

        if (self::isArrayPatternOrReplacement($args[0]) || self::isArrayPatternOrReplacement($args[1])) {
            throw new \LogicException(
                'preg_replace() array $pattern/$replacement is not supported for JIT/AOT in this compiler build'
            );
        }

        if (JitInternalStrictArg::rejectNullStringOrArray(
            $context,
            $args[0],
            'preg_replace',
            'pattern',
            1
        )) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        if (($args[0]->isNullConstant ?? false) || '' === JitStringBuiltinArg::compileTimeLiteral($args[0])) {
            return JitPregReplace::returnNullEmptyPattern($context, 'preg_replace');
        }

        $pattern = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'preg_replace',
            0,
            'pattern',
            'array|string'
        );
        $replacement = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'preg_replace',
            1,
            'replacement',
            'array|string'
        );
        if (JitInternalStrictArg::rejectNullStringOrArray($context, $args[2], 'preg_replace', 'subject', 3, false)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        JitPregSubject::requireStringOrArray($context, $args[2], 'preg_replace', 2, 'subject');
        $limitLit = 4 === $argc ? self::compileTimeLimit($args[3]) : -1;
        if (JitPregSubject::isStringOrCoercibleNullSubject($args[2])) {
            $folded = JitPregReplaceCompileTime::tryFoldReplaceString(
                $context,
                $args[0],
                $args[1],
                $args[2],
                $limitLit
            );
            if (null !== $folded) {
                return $folded;
            }
            return JitPregReplace::invokeString(
                $context,
                $pattern,
                $replacement,
                JitStringBuiltinArg::lower($context, $args[2], 'preg_replace', 2, 'subject', 'array|string', null, false),
                $limit
            );
        }

        return JitPregReplace::invokeArray($context, $pattern, $replacement, $args[2], $limit);
    }

    private static function lowerLimit(Context $context, JITVariable $arg): Value
    {
        $lit = self::compileTimeLimit($arg);
        if (null !== $lit) {
            return $context->constantFromInteger($lit, 'int64');
        }

        // Soft-null DEP+coerce on 8.4 (php_pcre.c Z_PARAM_LONG; #21655).
        return JitChr::lowerZParamLongArg($context, $arg, 'preg_replace', 4, 'limit');
    }

    private static function compileTimeLimit(JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type
            && JITVariable::KIND_VALUE === $arg->kind) {
            if (null !== ($arg->compileTimeLong ?? null)) {
                return (int) $arg->compileTimeLong;
            }
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type
            && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return VmMath::floatToZendLong((float) $const->constDouble());
            }
        }

        return null;
    }

    /**
     * @return string|list<string>
     */
    private static function patternOrReplacementOperand(
        Variable $var,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): string|array {
        if (Variable::TYPE_STRING === $var->type) {
            return VmString::coerceStringBuiltinArg($arg, $function, $argIndex, $paramName);
        }

        $values = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $value]) {
            $values[] = VmString::coerceStringBuiltinArg($value, $function, $argIndex, $paramName);
        }

        return $values;
    }

    private static function isArrayPatternOrReplacement(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }

        return 0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY);
    }
}
