<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mb_list_encodings() — supported encoding names (php-src ext/mbstring/mbstring.c; #15448). */
final class mb_list_encodings extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_list_encodings');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            MbstringState::hashTableFromStringList(MbstringEncodingRegistry::listEncodings())
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_list_encodings() JIT is not supported in this compiler build'
        );
    }
}
