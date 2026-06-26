<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\RuntimeStrictness;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/**
 * VM preg_match() — native PCRE via VmPregNative (issue #4874).
 *
 * JIT/AOT use {@see StringPregMatchJit} (libpcre2 via LLVM externals, issue #5289).
 */
final class VmPreg
{
    public const MAX_PATTERN_BYTES = 4096;

    /** Last PREG_* code from VM preg_* (Zend ext/pcre/php_pcre.c). */
    private static int $lastError = 0;

    public static function lastError(): int
    {
        return self::$lastError;
    }

    public static function lastErrorMsg(): string
    {
        return self::errorMsgForCode(self::$lastError);
    }

    public static function errorMsgForCode(int $code): string
    {
        return match ($code) {
            0 => 'No error',
            1 => 'Internal error',
            4 => 'Malformed UTF-8 characters, possibly incorrectly encoded',
            5 => 'The offset did not correspond to the beginning of a valid UTF-8 code point',
            2 => 'Backtrack limit exhausted',
            3 => 'Recursion limit exhausted',
            6 => 'JIT stack limit exhausted',
            default => 'Unknown error',
        };
    }

    private static function syncLastErrorFromNative(): void
    {
        self::$lastError = VmPregNative::lastError();
    }

    public static function validatePregMatchFlags(int $flags): void
    {
        $allowed = self::PREG_MATCH_ALLOWED_FLAGS;
        if (0 !== ($flags & ~$allowed)) {
            throw new \LogicException(
                'preg_match() flags must be a combination of PREG_OFFSET_CAPTURE and PREG_UNMATCHED_AS_NULL in this compiler build'
            );
        }
    }

    public static function validatePregMatchAllFlags(int $flags): void
    {
        $allowed = self::PREG_MATCH_ALLOWED_FLAGS
            | StdlibConstants::PREG_PATTERN_ORDER
            | StdlibConstants::PREG_SET_ORDER;
        if (0 !== ($flags & ~$allowed)) {
            throw new \LogicException(
                'preg_match_all() flags must be a combination of PREG_PATTERN_ORDER, PREG_SET_ORDER, PREG_OFFSET_CAPTURE, and PREG_UNMATCHED_AS_NULL in this compiler build'
            );
        }
    }

    private const PREG_MATCH_ALLOWED_FLAGS = StdlibConstants::PREG_OFFSET_CAPTURE
        | StdlibConstants::PREG_UNMATCHED_AS_NULL;

    /**
     * Z_PARAM_ARRAY_STR on preg_* $subject (#7154, ext/pcre/php_pcre.c).
     *
     * @throws \TypeError
     */
    public static function requireStringOrArraySubject(
        Variable $var,
        string $function,
        int $argIndex = 2,
        string $paramName = 'subject'
    ): Variable {
        return self::requireStringOrArrayArg($var, $function, $argIndex, $paramName);
    }

    /**
     * Z_PARAM_STR_OR_ARR on preg and str replace $subject with null to empty string (#11938).
     *
     * @throws \TypeError
     */
    public static function resolveStringOrArraySubject(
        Frame $frame,
        Variable $var,
        string $function,
        int $argIndex = 2,
        string $paramName = 'subject'
    ): Variable {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            if (InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(
                    self::stringOrArraySubjectTypeError($function, $argIndex, $paramName, 'null')
                );
            }
            $empty = new Variable();
            $empty->string('');

            return $empty;
        }

        return self::requireStringOrArrayArg($var, $function, $argIndex, $paramName);
    }

    /**
     * Z_PARAM_STR_OR_ARR on preg_* $pattern / $replacement (ext/pcre/php_pcre.c).
     *
     * @throws \TypeError
     */
    public static function requireStringOrArrayArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): Variable {
        $var = $var->resolveIndirect();
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(
                self::stringOrArraySubjectTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    EnumCaseSupport::typeNameForVariable($var)
                )
            );
        }
        if (Variable::TYPE_STRING === $var->type || Variable::TYPE_ARRAY === $var->type) {
            return $var;
        }

        throw new \TypeError(
            self::stringOrArraySubjectTypeError($function, $argIndex, $paramName, self::subjectTypeLabel($var))
        );
    }

    private static function stringOrArraySubjectTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type array|string, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }

    private static function subjectTypeLabel(Variable $var): string
    {
        $var = $var->resolveIndirect();
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
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }

    public static function pregMatch(
        string $pattern,
        string $subject,
        ?array &$matches = null,
        int $flags = 0,
        int $offset = 0
    ) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }
        self::validatePregMatchFlags($flags);

        $result = VmPregNative::pregMatch($pattern, $subject, $matches, $flags, $offset);
        self::syncLastErrorFromNative();

        return $result;
    }

    /**
     * @param-out array $matches
     */
    public static function pregMatchAll(
        string $pattern,
        string $subject,
        ?array &$matches = null,
        int $flags = 0,
        int $offset = 0
    ) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }
        self::validatePregMatchAllFlags($flags);

        $result = VmPregNative::pregMatchAll($pattern, $subject, $matches, $flags, $offset);
        self::syncLastErrorFromNative();

        return $result;
    }

    /**
     * @param string|list<string> $subject
     *
     * @return string|list<string>|false|null
     */
    public static function pregFilter(
        string $pattern,
        string $replacement,
        string|array $subject,
        int $limit = -1,
        int $flags = 0
    ) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }

        $result = VmPregNative::pregFilter($pattern, $replacement, $subject, $limit, $flags);
        self::syncLastErrorFromNative();
        if (false === $result) {
            return false;
        }

        return $result;
    }

    /**
     * @param string|list<string>        $pattern
     * @param string|list<string>        $replacement
     * @param string|list<string>        $subject
     *
     * @return string|list<string>|false
     */
    public static function pregReplace(
        string|array $pattern,
        string|array $replacement,
        string|array $subject,
        int $limit = -1,
        ?int &$count = null
    ) {
        self::assertPatternReplacementTypes($pattern, $replacement);

        if (\is_array($pattern)) {
            return self::pregReplaceArrayPatterns($pattern, $replacement, $subject, $limit, $count);
        }

        if (\is_array($replacement)) {
            throw new \TypeError(
                'preg_replace(): Argument #1 ($pattern) must be of type array when argument #2 ($replacement) is an array, string given'
            );
        }

        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }

        $result = VmPregNative::pregReplace($pattern, $replacement, $subject, $limit, $count);
        self::syncLastErrorFromNative();
        if (null === $result) {
            return false;
        }

        return $result;
    }

    /**
     * @param list<string>         $pattern
     * @param list<string>|string  $replacement
     * @param string|list<string>  $subject
     *
     * @return string|list<string>|false
     */
    private static function pregReplaceArrayPatterns(
        array $pattern,
        array|string $replacement,
        string|array $subject,
        int $limit,
        ?int &$count = null
    ): string|array|false {
        $replacements = \is_array($replacement)
            ? $replacement
            : array_fill(0, \count($pattern), $replacement);
        while (\count($replacements) < \count($pattern)) {
            $replacements[] = '';
        }

        if (\is_array($subject)) {
            $out = [];
            $totalCount = 0;
            foreach ($subject as $key => $item) {
                if (!\is_string($item)) {
                    throw new \LogicException(
                        'preg_replace() array subject values must be strings in this compiler build'
                    );
                }
                $elemCount = 0;
                $replaced = self::pregReplaceArrayPatterns($pattern, $replacements, $item, $limit, $elemCount);
                if (false === $replaced) {
                    return false;
                }
                $out[$key] = $replaced;
                $totalCount += $elemCount;
            }
            if (null !== $count) {
                $count = $totalCount;
            }

            return $out;
        }

        $result = $subject;
        $totalCount = 0;
        foreach ($pattern as $index => $pat) {
            if (strlen($pat) > self::MAX_PATTERN_BYTES) {
                return false;
            }
            $repl = $replacements[$index] ?? '';
            $stepCount = 0;
            $step = VmPregNative::pregReplace($pat, $repl, $result, $limit, $stepCount);
            self::syncLastErrorFromNative();
            if (false === $step || null === $step) {
                return false;
            }
            $result = $step;
            $totalCount += $stepCount;
        }
        if (null !== $count) {
            $count = $totalCount;
        }

        return $result;
    }

    /**
     * @param string|list<string> $pattern
     * @param string|list<string> $replacement
     */
    private static function assertPatternReplacementTypes(string|array $pattern, string|array $replacement): void
    {
        if (\is_string($pattern) && \is_array($replacement)) {
            throw new \TypeError(
                'preg_replace(): Argument #1 ($pattern) must be of type array when argument #2 ($replacement) is an array, string given'
            );
        }
    }

    /**
     * @param array $offsetMatches preg_match PREG_OFFSET_CAPTURE
     *
     * @return array
     */
    public static function stripMatchOffsets(array $offsetMatches): array
    {
        $out = [];
        foreach ($offsetMatches as $key => $match) {
            if (\is_array($match) && isset($match[0]) && \is_string($match[0])) {
                $out[$key] = $match[0];
            } elseif (\is_string($match)) {
                $out[$key] = $match;
            } else {
                throw new \LogicException(
                    'preg_replace_callback() internal match shape invalid in this compiler build'
                );
            }
        }

        return $out;
    }

    /**
     * @return list<string>|list<array{0: string, 1: int}>|false
     */
    public static function pregSplit(string $pattern, string $subject, int $limit = -1, int $flags = 0) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }
        $allowed = StdlibConstants::PREG_SPLIT_NO_EMPTY
            | StdlibConstants::PREG_SPLIT_DELIM_CAPTURE
            | StdlibConstants::PREG_SPLIT_OFFSET_CAPTURE;
        if (0 !== ($flags & ~$allowed)) {
            throw new \LogicException(
                'preg_split() flags must be a combination of PREG_SPLIT_* constants in this compiler build'
            );
        }

        $result = VmPregNative::pregSplit($pattern, $subject, $limit, $flags);
        self::syncLastErrorFromNative();
        if (false === $result) {
            return false;
        }

        return $result;
    }

    /**
     * @param list<string>|list<array{0: string, 1: int}> $parts
     */
    public static function splitPartsToHashTable(array $parts, int $flags): HashTable
    {
        $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_SPLIT_OFFSET_CAPTURE);
        $ht = new HashTable();
        foreach ($parts as $part) {
            $ht->append(self::splitPartToVariable($part, $offsetCapture));
        }

        return $ht;
    }

    /**
     * @param string|array{0: string, 1: int} $part
     */
    private static function splitPartToVariable(string|array $part, bool $offsetCapture): Variable
    {
        $var = new Variable();
        if ($offsetCapture) {
            if (!\is_array($part) || !isset($part[0], $part[1]) || !\is_string($part[0]) || !\is_int($part[1])) {
                throw new \LogicException(
                    'preg_split() internal offset capture shape invalid in this compiler build'
                );
            }
            $pair = new HashTable();
            $str = new Variable();
            $str->string($part[0]);
            $pair->append($str);
            $off = new Variable();
            $off->int($part[1]);
            $pair->append($off);
            $var->array($pair);

            return $var;
        }
        if (!\is_string($part)) {
            throw new \LogicException(
                'preg_split() internal split part must be a string in this compiler build'
            );
        }
        $var->string($part);

        return $var;
    }
}
