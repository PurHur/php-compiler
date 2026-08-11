<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * preg_filter() — filter/replace with PCRE; only matched entries returned (ext/standard/pcre.c; #3250).
 *
 * VM via host {@see VmPreg::pregFilter}; JIT/AOT via {@see JitPregFilter}.
 */
final class preg_filter extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_filter');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/pcre/php_pcre.c — ArgumentCountError (#25407).
        $this->requireArgCountRange($frame, 'preg_filter', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR_OR_ARR soft-null: E_DEPRECATED array|string (php_pcre.stub.php; #23587).
        // Non-null still string-coerced here (array patterns remain a follow-up).
        $patternRaw = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $patternRaw->type) {
            if (\PHPCompiler\VM\InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(
                    'preg_filter(): Argument #1 ($pattern) must be of type array|string, null given'
                );
            }
            VmNullStringParamDeprecation::emit($frame, 'preg_filter', 0, 'pattern', 'array|string');
            VmPregFailure::warnEmptyRegularExpression($frame, 'preg_filter');
            // php-src php_pcre.c: array subject → empty array; string subject → null (#30068).
            $subjectVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $subjectVar->type) {
                $frame->returnVar->array(new HashTable());
            } else {
                $frame->returnVar->null();
            }

            return;
        }
        $pattern = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'preg_filter', 0, 'pattern');
        $replacement = VmReflection::stringArg($frame->calledArgs[1], 'preg_filter() replacement', 1);
        VmPregFailure::warnPatternCompileFailure($frame, 'preg_filter', $pattern);
        $subjectVar = VmPreg::resolveStringOrArraySubject(
            $frame,
            $frame->calledArgs[2],
            'preg_filter',
            2,
            'subject'
        );
        $limit = -1;
        if (isset($frame->calledArgs[3])) {
            // Z_PARAM_LONG $limit — soft-null DEP+coerce on 8.4 (php_pcre.c; #21655).
            $limit = VmMath::parseChrCodepointForFrame($frame, 3, 'preg_filter', 4, 'limit');
        }
        // Named count: may skip limit — use isset not argc (#19697).
        $hasCount = isset($frame->calledArgs[4]);
        $count = 0;

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $result = VmPreg::pregFilter($pattern, $replacement, $subjectVar->toString(), $limit, $count);
        } elseif (Variable::TYPE_ARRAY === $subjectVar->type) {
            $host = [];
            foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
                // php-src php_pcre_filter: convert_to_string per array subject (#27164).
                $hostKey = Variable::TYPE_INTEGER === $key->type
                    ? $key->toInt()
                    : $key->toString();
                $host[$hostKey] = $value->resolveIndirect()->toString(null, $frame);
            }
            $result = VmPreg::pregFilter($pattern, $replacement, $host, $limit, $count);
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
        if (!$this->requireArgCountRangeJit($context, $args, 'preg_filter', 3, 5)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        $limit = $argc >= 4
            ? self::lowerLimit($context, $args[3])
            : $context->getTypeFromString('int64')->constInt(-1, false);

        if (JitInternalStrictArg::rejectNullStringOrArray($context, $args[2], 'preg_filter', 'subject', 3, false)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        JitPregSubject::requireStringOrArray($context, $args[2], 'preg_filter', 2, 'subject');
        $limitLit = $argc >= 4 ? self::compileTimeLimit($args[3]) : -1;
        // Thin AOT: NestedJIT replace strings are corrupt — fold literal calls (#27181).
        if (JitPregSubject::isStringOrCoercibleNullSubject($args[2])) {
            $folded = JitPregReplaceCompileTime::tryFoldFilterString(
                $context,
                $args[0],
                $args[1],
                $args[2],
                $limitLit
            );
            if (null !== $folded) {
                return $folded;
            }
        } else {
            $folded = JitPregReplaceCompileTime::tryFoldFilterArray(
                $context,
                $args[0],
                $args[1],
                $args[2],
                $limitLit
            );
            if (null !== $folded) {
                return $folded;
            }
        }
        // Z_PARAM_STR $pattern — null TypeError on 8.4 forward profile (#20226).
        $pattern = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'preg_filter', 0, 'pattern')
            : JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'preg_filter', 0, 'pattern');
        $replacement = JitStringArg::lower($context, $args[1], 'preg_filter() replacement');
        if (JitPregSubject::isStringOrCoercibleNullSubject($args[2])) {
            return JitPregFilter::invoke(
                $context,
                $pattern,
                $replacement,
                new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    JitStringBuiltinArg::lower($context, $args[2], 'preg_filter', 2, 'subject', 'array|string', null, false)
                ),
                $limit
            );
        }

        return JitPregFilter::invoke(
            $context,
            $pattern,
            $replacement,
            $args[2],
            $limit
        );
    }

    private static function lowerLimit(Context $context, JITVariable $arg): Value
    {
        $lit = self::compileTimeLimit($arg);
        if (null !== $lit) {
            return $context->constantFromInteger($lit, 'int64');
        }

        // Soft-null DEP+coerce on 8.4 (php_pcre.c Z_PARAM_LONG; #21655).
        return JitChr::lowerZParamLongArg($context, $arg, 'preg_filter', 4, 'limit');
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
}
