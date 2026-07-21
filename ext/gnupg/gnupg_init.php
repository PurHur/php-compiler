<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;

/** gnupg_init() — gnupg object (PECL gnupg; #6668). */
final class gnupg_init extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_init() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('gnupg_init() requires a VM context');
        }
        $options = 1 === $argc
            ? VmGnupgArg::optionalArray($frame->calledArgs[0], 'gnupg_init', 1)
            : null;
        $result = VmGnupgCore::init($ctx, $options);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }
}
