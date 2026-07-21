<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;

/** gnupg_addencryptkey() (#6668). */
final class gnupg_addencryptkey extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_addencryptkey');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_addencryptkey() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $object = VmGnupgArg::requireGnupg($frame->calledArgs[0], 'gnupg_addencryptkey', 1);
        $key = VmGnupgArg::requireString($frame->calledArgs[1], 'gnupg_addencryptkey', 2, 'key');
        $ok = VmGnupgCore::addEncryptKey($object, $key);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}
