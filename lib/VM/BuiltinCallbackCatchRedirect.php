<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Builtin user-callback throw matched catch — run catch on the outer runFrames loop (#14104, #14105).
 *
 * Isolated {@see \PHPCompiler\VM::invokeClosureFrom} must not execute user catch handlers inside
 * nested runFrames (would resume the builtin call site after catch).
 */
final class BuiltinCallbackCatchRedirect extends \Exception
{
    public function __construct(public readonly Frame $catchFrame)
    {
        parent::__construct('Builtin callback exception deferred to outer catch');
    }
}
