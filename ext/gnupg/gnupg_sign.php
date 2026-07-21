<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;

/** gnupg_sign() (#6668). */
final class gnupg_sign extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_sign');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_sign() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $object = VmGnupgArg::requireGnupg($frame->calledArgs[0], 'gnupg_sign', 1);
        $text = VmGnupgArg::requireString($frame->calledArgs[1], 'gnupg_sign', 2, 'text');
        $result = VmGnupgCore::sign($object, $text);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}
