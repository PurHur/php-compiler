<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;

/** gnupg_clearencryptkeys() (#6668). */
final class gnupg_clearencryptkeys extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_clearencryptkeys');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_clearencryptkeys() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $object = VmGnupgArg::requireGnupg($frame->calledArgs[0], 'gnupg_clearencryptkeys', 1);
        VmGnupgObject::clearEncryptKeys($object);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
