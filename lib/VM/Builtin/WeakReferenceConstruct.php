<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** Disallow direct instantiation (issue #1366). */
final class WeakReferenceConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('Cannot directly instantiate WeakReference');
    }
}
