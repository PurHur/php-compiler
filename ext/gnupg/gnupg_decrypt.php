<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;

/** gnupg_decrypt() (#6668). */
final class gnupg_decrypt extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_decrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_decrypt() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $object = VmGnupgArg::requireGnupg($frame->calledArgs[0], 'gnupg_decrypt', 1);
        $text = VmGnupgArg::requireString($frame->calledArgs[1], 'gnupg_decrypt', 2, 'enctext');
        $result = VmGnupgCore::decrypt($object, $text);
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
