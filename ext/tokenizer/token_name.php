<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** token_name() — map token id to name (ext/tokenizer/tokenizer_data.c; issue #6940). */
final class token_name extends Internal
{
    public function __construct()
    {
        parent::__construct('token_name');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('token_name() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $type = $frame->calledArgs[0]->toInt();

        $name = TokenConstants::nameForId($type);
        if (null !== $name) {
            $frame->returnVar->string($name);

            return;
        }

        if (\function_exists('token_name')) {
            $hostName = \token_name($type);
            if (\is_string($hostName) && '' !== $hostName) {
                $frame->returnVar->string($hostName);

                return;
            }
        }

        throw new \Error('token_name() is not implemented in this compiler build (issue #3171)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('token_name() is not implemented for JIT in this compiler build (issue #3171)');
    }
}
