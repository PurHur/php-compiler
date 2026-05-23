<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** chunk_split() — insert a separator every N bytes (subset of PHP). */
final class chunk_split extends Internal
{
    public function __construct()
    {
        parent::__construct('chunk_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('chunk_split() requires one to three arguments in this compiler build');
        }
        $string = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $string->type) {
            throw new \LogicException('chunk_split() first argument must be a string in this compiler build');
        }
        $length = 76;
        if ($argc >= 2) {
            $lenArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lenArg->type) {
                throw new \LogicException('chunk_split() length must be an integer in this compiler build');
            }
            $length = $lenArg->toInt();
        }
        $separator = "\r\n";
        if (3 === $argc) {
            $sepArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $sepArg->type) {
                throw new \LogicException('chunk_split() separator must be a string in this compiler build');
            }
            $separator = $sepArg->toString();
        }
        $frame->returnVar->string(
            VmString::chunkSplit($string->toString(), $length, $separator)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('chunk_split() requires one to three arguments in this compiler build');
        }
        $input = $this->jitString($context, $args[0], 'chunk_split() argument #1');
        $i64 = $context->getTypeFromString('int64');
        $chunkLen = $i64->constInt(76, false);
        if ($argc >= 2) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('chunk_split() length must be an integer in this compiler build');
            }
            $chunkLen = $context->helper->loadValue($args[1]);
        }
        if ($argc >= 3) {
            $separator = $this->jitString($context, $args[2], 'chunk_split() argument #3');
        } else {
            $separator = $context->builder->load($context->constantStringFromString("\r\n"));
        }

        return JitChunkSplit::split($context, $input, $chunkLen, $separator);
    }
}
