<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** token_get_all() — PHP source tokenizer (ext/tokenizer/tokenizer.c; issue #6940, #3171). */
final class token_get_all extends Internal
{
    public function __construct()
    {
        parent::__construct('token_get_all');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('token_get_all() expects at least 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $source = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'token_get_all', 0, 'source');
        $flags = 0;
        if ($argc >= 2) {
            $flags = $frame->calledArgs[1]->toInt();
        }

        if (\function_exists('token_get_all')) {
            $tokens = \token_get_all($source, $flags);
            if (!\is_array($tokens)) {
                throw new \Error('token_get_all() is not implemented in this compiler build (issue #3171)');
            }
            $frame->returnVar->array(VmTokenizer::hostTokensToHashTable($tokens));

            return;
        }

        throw new \Error('token_get_all() is not implemented in this compiler build (issue #3171)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('token_get_all() is not implemented for JIT in this compiler build (issue #3171)');
    }
}
