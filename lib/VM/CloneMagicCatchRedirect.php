<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Throw during nested __clone() matched an outer try/catch — run catch on the clone
 * opcode's runFrames, not inside the isolated __clone stack (#23527, #12068).
 *
 * Mirrors {@see BuiltinCallbackCatchRedirect}: jumping to the caller catch while still
 * inside nested runFrames would resume the statement after `clone` once the nested
 * stack unwinds.
 */
final class CloneMagicCatchRedirect extends \Exception
{
    public function __construct(public readonly Frame $catchFrame)
    {
        parent::__construct('__clone() exception deferred to outer catch');
    }
}
