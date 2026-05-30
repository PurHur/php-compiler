<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\FiberSupport;

/** Fiber::suspend(mixed $value = null): mixed — VM (#3130). */
final class FiberSuspend extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('suspend');
    }

    public function execute(Frame $frame): void
    {
        FiberSupport::suspend($frame);
    }
}
