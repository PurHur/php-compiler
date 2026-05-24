<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefSupport;

/** WeakReference::get() — VM stub (#1366). */
final class WeakReferenceGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('get');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('WeakReference::get() called without $this');
        }
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakReference');
        if (null === $frame->returnVar) {
            return;
        }
        WeakRefSupport::copyAliveTarget(
            $frame->returnVar,
            WeakRefSupport::targetSlot($receiver->toObject())
        );
    }
}
