<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** token_get_all() — PHP source tokenizer (ext/tokenizer/tokenizer.c; issue #6940, #3171, #4561). */
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

        // php-src Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null DEP+coerce
        // (#30257; #21503 soft path without strict_types; tokenizer.stub.php string $code).
        $source = VmString::trimFamilyStringArgForFrame($frame, 0, 'token_get_all', 0, 'code');
        $flags = 0;
        if ($argc >= 2) {
            $flags = $frame->calledArgs[1]->toInt();
        }

        $tokens = VmTokenizer::tokenize($source, $flags);
        $frame->returnVar->array(VmTokenizer::hostTokensToHashTable($tokens));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitTokenGetAll::lower($context, ...$args);
    }
}
