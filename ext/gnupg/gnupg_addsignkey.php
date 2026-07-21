<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** gnupg_addsignkey() (#6668). */
final class gnupg_addsignkey extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_addsignkey');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_addsignkey() expects 2 or 3 arguments, %d given',
                $argc
            ));
        }
        $object = VmGnupgArg::requireGnupg($frame->calledArgs[0], 'gnupg_addsignkey', 1);
        $key = VmGnupgArg::requireString($frame->calledArgs[1], 'gnupg_addsignkey', 2, 'key');
        $pass = null;
        if (3 === $argc) {
            $passVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING === $passVar->type) {
                $pass = $passVar->toString();
            }
        }
        $ok = VmGnupgCore::addSignkey($object, $key, $pass);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}
