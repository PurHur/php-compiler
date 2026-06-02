<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/** __toString (and similar) throw was caught by user try/catch during nested invoke (#4284). */
final class MagicMethodInvocationAborted extends \Exception
{
    public function __construct()
    {
        parent::__construct('Magic method invocation aborted after user catch');
    }
}
