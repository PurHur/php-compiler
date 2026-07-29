<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * preg_replace_callback() — VM with any callable; JIT/AOT string user-function names (#1177, #4442).
 */
final class preg_replace_callback extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_replace_callback');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 6) {
            throw new \LogicException(
                'preg_replace_callback() expects 3 to 6 arguments in this compiler build'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('preg_replace_callback() requires VM context in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }

        // Z_PARAM_STR_OR_ARR soft-null: E_DEPRECATED array|string (php_pcre.stub.php; #23587).
        $patternRaw = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $patternRaw->type) {
            if (\PHPCompiler\VM\InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(
                    'preg_replace_callback(): Argument #1 ($pattern) must be of type array|string, null given'
                );
            }
            VmNullStringParamDeprecation::emit($frame, 'preg_replace_callback', 0, 'pattern', 'array|string');
            VmPregFailure::warnEmptyRegularExpression($frame, 'preg_replace_callback');
            $frame->returnVar->null();

            return;
        }
        // Non-null still string-coerced here (array patterns remain a follow-up).
        $pattern = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'preg_replace_callback', 0, 'pattern');
        VmPregFailure::warnPatternCompileFailure($frame, 'preg_replace_callback', $pattern);
        $callbackVar = $frame->calledArgs[1]->resolveIndirect();
        // $subject soft-null: E_DEPRECATED + '' on 8.4 (php-src php_pcre.c / #21318, re-#21198).
        $subjectVar = VmPreg::resolveStringOrArraySubject(
            $frame,
            $frame->calledArgs[2],
            'preg_replace_callback',
            2,
            'subject'
        );
        $limit = -1;
        if (isset($frame->calledArgs[3])) {
            $limitVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitVar->type) {
                throw new \TypeError(
                    'preg_replace_callback(): Argument #4 ($limit) must be of type int, '
                    .self::typeLabel($limitVar).' given'
                );
            }
            $limit = $limitVar->toInt();
        }
        // Named count: may skip limit — use isset not argc (#19697).
        $hasCount = isset($frame->calledArgs[4]);
        $flags = 0;
        if (isset($frame->calledArgs[5])) {
            $flagsVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \TypeError(
                    'preg_replace_callback(): Argument #6 ($flags) must be of type int, '
                    .self::typeLabel($flagsVar).' given'
                );
            }
            $flags = $flagsVar->toInt();
        }

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $count = 0;
            $result = VmPregReplaceCallback::invoke(
                $frame->vmContext,
                $pattern,
                $callbackVar,
                $subjectVar->toString(),
                $limit,
                $count,
                $flags
            );
            if ($hasCount) {
                $frame->calledArgs[4]->resolveIndirect()->int($count);
            }
            self::assignReturn($frame, $result);

            return;
        }

        $totalCount = 0;
        $ht = new HashTable();
        foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_STRING !== $value->type) {
                throw new \TypeError(
                    'preg_replace_callback(): Argument #3 ($subject) must be of type array|string, '
                    .self::typeLabel($value).' given'
                );
            }
            $elemCount = 0;
            $result = VmPregReplaceCallback::invoke(
                $frame->vmContext,
                $pattern,
                $callbackVar,
                $value->toString(),
                $limit,
                $elemCount,
                $flags
            );
            $totalCount += $elemCount;
            if (false === $result) {
                $frame->returnVar->bool(false);

                return;
            }
            $keyVar = new Variable();
            if (Variable::TYPE_INTEGER === $key->type) {
                $keyVar->int($key->toInt());
            } else {
                $keyVar->string($key->toString());
            }
            $outVal = new Variable();
            $outVal->string($result);
            array_map::appendKeyedCopy($ht, $keyVar, $outVal);
        }
        if ($hasCount) {
            $frame->calledArgs[4]->resolveIndirect()->int($totalCount);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException(
                'preg_replace_callback() JIT/AOT lowering requires exactly three arguments in this compiler build'
            );
        }

        JitPregSubject::requireStringOrArray($context, $args[2], 'preg_replace_callback', 2, 'subject');
        if (!JitPregSubject::isStringOrCoercibleNullSubject($args[2])) {
            throw new \LogicException(
                'preg_replace_callback() array subject is not supported for JIT/AOT in this compiler build'
            );
        }

        // Z_PARAM_STR $pattern — null TypeError on 8.4 forward profile (#20226).
        $pattern = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'preg_replace_callback', 0, 'pattern')
            : JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'preg_replace_callback', 0, 'pattern');
        // $subject soft-null DEP+coerce on 8.4 (#21318; php-src php_pcre.c).
        $subject = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[2], 'preg_replace_callback', 2, 'subject')
            : JitStringBuiltinArg::lower(
                $context,
                $args[2],
                'preg_replace_callback',
                2,
                'subject',
                'array|string',
                null,
                false
            );

        return JitPregReplaceCallback::invoke(
            $context,
            $pattern,
            $args[1],
            $subject
        );
    }

    /**
     * @param string|false $result
     */
    private static function assignReturn(Frame $frame, string|false $result): void
    {
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    private static function typeLabel(Variable $var): string
    {
        $var = $var->resolveIndirect();

        return match ($var->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
