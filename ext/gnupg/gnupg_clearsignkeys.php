<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;

/** gnupg_clearsignkeys() (#6668). */
final class gnupg_clearsignkeys extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_clearsignkeys');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_clearsignkeys() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $object = VmGnupgArg::requireGnupg($frame->calledArgs[0], 'gnupg_clearsignkeys', 1);
        VmGnupgObject::clearSignKeys($object);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
