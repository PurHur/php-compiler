<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Raised when compiled exit/die terminates script execution (issue #269).
 */
final class ScriptExit extends \Exception
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Script exit', $status);
    }
}
