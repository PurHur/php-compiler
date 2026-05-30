<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_get_contents() — drain stream from current or given offset (#3142).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(stream_get_contents)
 */
final class stream_get_contents extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('stream_get_contents() requires one to three arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (!is_resource_::isResource($handleVar)) {
            throw new \LogicException('stream_get_contents() expects a stream resource');
        }
        $maxlength = -1;
        $offset = -1;
        if ($argc >= 2) {
            $maxVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \LogicException('stream_get_contents() maxlength must be an integer in this compiler build');
            }
            $maxlength = $maxVar->toInt();
        }
        if (3 === $argc) {
            $offVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $offVar->type) {
                throw new \LogicException('stream_get_contents() offset must be an integer in this compiler build');
            }
            $offset = $offVar->toInt();
        }
        $data = VmFs::streamGetContents($handleVar->toInt(), $maxlength, $offset);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('stream_get_contents() requires one to three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'stream_get_contents() handle'),
            $i64
        );
        if ($argc >= 2) {
            $maxlength = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'stream_get_contents() maxlength'),
                $i64
            );
        } else {
            $maxlength = $i64->constInt(-1, true);
        }
        if (3 === $argc) {
            $offset = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'stream_get_contents() offset'),
                $i64
            );
        } else {
            $offset = $i64->constInt(-1, true);
        }

        return JitStreamGetContents::invoke($context, $handle, $maxlength, $offset);
    }
}
