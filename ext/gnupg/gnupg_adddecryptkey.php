<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;

/** gnupg_adddecryptkey() (#6668). */
final class gnupg_adddecryptkey extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_adddecryptkey');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_adddecryptkey() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $object = VmGnupgArg::requireGnupg($frame->calledArgs[0], 'gnupg_adddecryptkey', 1);
        $key = VmGnupgArg::requireString($frame->calledArgs[1], 'gnupg_adddecryptkey', 2, 'key');
        $pass = VmGnupgArg::requireString($frame->calledArgs[2], 'gnupg_adddecryptkey', 3, 'passphrase');
        $ok = VmGnupgCore::addDecryptkey($object, $key, $pass);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}
