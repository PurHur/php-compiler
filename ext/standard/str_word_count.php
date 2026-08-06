<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrWordCount;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_word_count() — count words or return word list (subset of PHP; issue #2382, #3584).
 *
 * VM: all formats via {@see VmString::str_word_count()}.
 * JIT/AOT: {@see StringStrWordCount} → StrWordCountJitHelper PHP (#14651).
 */
final class str_word_count extends Internal
{
    public function __construct()
    {
        parent::__construct('str_word_count');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('str_word_count() accepts one to three arguments in this compiler build');
        }
        $string = self::vmStringArg($frame, 0, 'string');
        $format = 0;
        if ($argc >= 2) {
            $formatArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $formatArg->type) {
                throw new \LogicException('str_word_count() argument #2 must be an integer in this compiler build');
            }
            $format = $formatArg->toInt();
        }
        $chars = '';
        if (3 === $argc) {
            $chars = InternalStrictArg::resolveCoercibleStringArg($frame, 2, 'str_word_count', 'chars');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmString::str_word_count($string, $format, $chars);
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        $ht = new HashTable();
        if (1 === $format) {
            foreach ($result as $word) {
                $value = new Variable();
                $value->string($word);
                $ht->append($value);
            }
        } else {
            foreach ($result as $pos => $word) {
                $value = new Variable();
                $value->string($word);
                $ht->addIndex((int) $pos, $value);
            }
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('str_word_count() accepts one to three arguments in this compiler build');
        }

        $literal = $args[0]->compileTimeString ?? null;
        $formatCt = null;
        if ($argc >= 2) {
            $formatCt = $args[1]->compileTimeLong ?? null;
        }
        $charsCt = null;
        if (3 === $argc) {
            $charsCt = $args[2]->compileTimeString ?? null;
            if (null === $charsCt && JITVariable::TYPE_STRING === $args[2]->type) {
                $charsCt = '';
            }
        }

        // Fold when the string is a compile-time literal and format/chars are known
        // (1-arg defaults format=0; #27019 also covers the runtime NestedJIT path).
        if (null !== $literal && (1 === $argc || (null !== $formatCt && (2 === $argc || null !== $charsCt)))) {
            $format = 1 === $argc ? 0 : (int) $formatCt;
            $chars = $charsCt ?? '';
            $result = VmString::str_word_count($literal, $format, $chars);
            if (\is_int($result)) {
                return $context->constantFromInteger($result, 'int64');
            }

            return StringStrWordCount::hashTableFromVmResult($context, $result, $format);
        }

        $str = null !== $literal
            ? $context->builder->load($context->constantStringFromString($literal))
            : self::jitStringArg($context, $args[0], 0, 'string');
        StringStrWordCount::ensureLinked($context);

        $formatVal = 1 === $argc
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : (null !== $formatCt
                ? $context->getTypeFromString('int64')->constInt((int) $formatCt, false)
                : StringStrWordCount::jitFormatArg($context, $args[1]));

        if (1 === $argc || (null !== $formatCt && 0 === (int) $formatCt)) {
            return $context->builder->call(
                $context->lookupFunction('phpc_str_word_count_count'),
                $str
            );
        }

        $charsArg = null !== $charsCt
            ? $context->builder->load($context->constantStringFromString($charsCt))
            : (3 === $argc
                ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[2], 'str_word_count', 2, 'chars')
                : $context->builder->load($context->constantStringFromString('')));

        return $context->builder->call(
            $context->lookupFunction('phpc_str_word_count_words'),
            $str,
            $formatVal,
            $charsArg
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'str_word_count', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'str_word_count',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'str_word_count',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'str_word_count',
            $argIndex,
            $paramName
        );
    }
}
