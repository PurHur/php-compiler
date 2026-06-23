<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
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
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException(
                'preg_replace() expects 3 to 5 arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $patternVar = VmPreg::requireStringOrArrayArg($frame->calledArgs[0], 'preg_replace', 0, 'pattern');
        $replacementVar = VmPreg::requireStringOrArrayArg($frame->calledArgs[1], 'preg_replace', 1, 'replacement');
        $subjectVar = VmPreg::requireStringOrArraySubject(
            $frame->calledArgs[2],
            'preg_replace',
            2,
            'subject'
        );
        $limit = -1;
        if (4 === $argc) {
            $limitVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitVar->type) {
                throw new \LogicException(
                    'preg_replace() limit must be an integer in this compiler build'
                );
            }
            $limit = $limitVar->toInt();
        }

        $pattern = self::patternOrReplacementOperand($patternVar, $frame->calledArgs[0], 'preg_replace', 0, 'pattern');
        $replacement = self::patternOrReplacementOperand(
            $replacementVar,
            $frame->calledArgs[1],
            'preg_replace',
            1,
            'replacement'
        );
        $hasCount = $argc >= 5;
        $count = 0;

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $result = VmPreg::pregReplace($pattern, $replacement, $subjectVar->toString(), $limit, $count);
        } elseif (Variable::TYPE_ARRAY === $subjectVar->type) {
            $host = [];
            foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
                if (Variable::TYPE_STRING !== $value->type) {
                    throw new \LogicException(
                        'preg_replace() array values must be strings in this compiler build'
                    );
                }
                $hostKey = Variable::TYPE_INTEGER === $key->type
                    ? $key->toInt()
                    : $key->toString();
                $host[$hostKey] = $value->toString();
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
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException(
                'preg_replace() expects 3 to 4 arguments in this compiler build'
            );
        }
        $limit = 4 === $argc
            ? self::lowerLimit($context, $args[3])
            : $context->getTypeFromString('int64')->constInt(-1, false);

        if (self::isArrayPatternOrReplacement($args[0]) || self::isArrayPatternOrReplacement($args[1])) {
            throw new \LogicException(
                'preg_replace() array $pattern/$replacement is not supported for JIT/AOT in this compiler build'
            );
        }

        $pattern = JitStringArg::lower($context, $args[0], 'preg_replace() pattern');
        $replacement = JitStringArg::lower($context, $args[1], 'preg_replace() replacement');
        JitPregSubject::requireStringOrArray($context, $args[2], 'preg_replace', 2, 'subject');
        if (JITVariable::TYPE_STRING === $args[2]->type) {
            return JitPregReplace::invokeString(
                $context,
                $pattern,
                $replacement,
                JitStringArg::lower($context, $args[2], 'preg_replace() subject'),
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

        return JitLongArg::lower($context, $arg, 'preg_replace() argument #4 ($limit)');
    }

    private static function compileTimeLimit(JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
            || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
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
