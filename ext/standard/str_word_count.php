<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_word_count() — count words or return word list (subset of PHP; issue #2382, #3584).
 *
 * VM: all formats via {@see VmString::str_word_count()}.
 * JIT/AOT: format 0 via {@see JitStrWordCount} LLVM lowering; formats 1/2 via C runtime.
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
        $str = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $str->type) {
            throw new \LogicException('str_word_count() argument #1 must be a string in this compiler build');
        }
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
            $charsArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $charsArg->type) {
                throw new \LogicException('str_word_count() argument #3 must be a string in this compiler build');
            }
            $chars = $charsArg->toString();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmString::str_word_count($str->toString(), $format, $chars);
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

        if (null !== $literal && null !== $formatCt && (2 === $argc || null !== $charsCt)) {
            $format = (int) $formatCt;
            $chars = $charsCt ?? '';
            $result = VmString::str_word_count($literal, $format, $chars);
            if (\is_int($result)) {
                return $context->constantFromInteger($result, 'int64');
            }

            return JitStrWordCount::hashTableFromVmResult($context, $result, $format);
        }

        $str = null !== $literal
            ? $context->builder->load($context->constantStringFromString($literal))
            : $this->jitString($context, $args[0], 'str_word_count() argument #1');

        $formatVal = 1 === $argc
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : (null !== $formatCt
                ? $context->getTypeFromString('int64')->constInt((int) $formatCt, false)
                : JitStrWordCount::jitFormatArg($context, $args[1]));

        if (null !== $formatCt && 0 === (int) $formatCt && $argc < 3) {
            return JitStrWordCount::count($context, $str);
        }

        $charsVal = null;
        if (3 === $argc) {
            $charsVal = null !== $charsCt
                ? $context->builder->load($context->constantStringFromString($charsCt))
                : $this->jitString($context, $args[2], 'str_word_count() argument #3');
        }

        return JitStrWordCount::wordHashTableRuntime($context, $str, $formatVal, $charsVal);
    }
}
