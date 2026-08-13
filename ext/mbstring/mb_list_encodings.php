<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_list_encodings() — supported encoding names (php-src ext/mbstring/mbstring.c; #15448, #30795).
 *
 * JIT/AOT: compile-time constant HT via {@see JitMbEncodingRegistry} (peer pdo_drivers).
 */
final class mb_list_encodings extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_list_encodings');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'mb_list_encodings() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            MbstringState::hashTableFromStringList(MbstringEncodingRegistry::listEncodings())
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbEncodingRegistry::foldListEncodings($context, ...$args);
    }
}
