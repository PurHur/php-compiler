<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\RuntimeStrictness;
use PHPCompiler\VM\EnumCaseSupport;
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
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException(
                'preg_replace() expects 3 to 4 arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $patternVar = self::requireStringOrArrayPattern(
            $frame->calledArgs[0],
            'preg_replace',
            0,
            'pattern'
        );
        $replacementVar = self::requireStringOrArrayPattern(
            $frame->calledArgs[1],
            'preg_replace',
            1,
            'replacement'
        );
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

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $result = self::replaceSubjectString(
                $patternVar,
                $replacementVar,
                $subjectVar->toString(),
                $limit
            );
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
                $replaced = self::replaceSubjectString(
                    $patternVar,
                    $replacementVar,
                    $value->toString(),
                    $limit
                );
                if (false === $replaced) {
                    $result = false;
                    break;
                }
                $host[$hostKey] = $replaced;
            }
            $result = $result ?? $host;
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
     * @return string|list<string>|false
     */
    private static function replaceSubjectString(
        Variable $patternVar,
        Variable $replacementVar,
        string $subject,
        int $limit
    ): string|array|false {
        if (Variable::TYPE_STRING === $patternVar->type) {
            if (Variable::TYPE_ARRAY === $replacementVar->type) {
                throw new \TypeError(
                    'preg_replace(): Argument #1 ($pattern) must be of type array when argument #2 ($replacement) is an array'
                );
            }

            return VmPreg::pregReplace(
                $patternVar->toString(),
                $replacementVar->toString(),
                $subject,
                $limit
            );
        }

        $patterns = self::stringListFromArrayArg($patternVar, 'preg_replace', 0, 'pattern');
        $replacementIsArray = Variable::TYPE_ARRAY === $replacementVar->type;
        $replacements = $replacementIsArray
            ? self::stringListFromArrayArg($replacementVar, 'preg_replace', 1, 'replacement')
            : null;
        if ($replacementIsArray && \count($patterns) !== \count($replacements)) {
            throw new \ValueError(
                'preg_replace(): Argument #1 ($pattern) and argument #2 ($replacement) must have the same length'
            );
        }
        $scalarReplacement = $replacementIsArray ? null : $replacementVar->toString();
        $result = $subject;
        foreach ($patterns as $i => $pattern) {
            $replacement = null !== $replacements ? $replacements[$i] : $scalarReplacement;
            $next = VmPreg::pregReplace($pattern, $replacement, $result, $limit);
            if (false === $next) {
                return false;
            }
            $result = $next;
        }

        return $result;
    }

    /** @return list<string> */
    private static function stringListFromArrayArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): array {
        $list = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $value]) {
            if (Variable::TYPE_STRING !== $value->type) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($%s) must contain only string elements',
                    $function,
                    $argIndex + 1,
                    $paramName
                ));
            }
            $list[] = $value->toString();
        }

        return $list;
    }

    private static function requireStringOrArrayPattern(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): Variable {
        $var = $var->resolveIndirect();
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING === $var->type || Variable::TYPE_ARRAY === $var->type) {
            return $var;
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type array|string, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            self::patternArgTypeLabel($var)
        ));
    }

    private static function patternArgTypeLabel(Variable $var): string
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
