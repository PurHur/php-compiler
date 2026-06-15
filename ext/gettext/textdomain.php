<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** textdomain() — set/get active message domain (php-src ext/gettext/gettext.c; #3449). */
final class textdomain extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('textdomain');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'textdomain() expects at most 1 argument, %d given',
                $argc
            ));
        }
        $domain = 1 === $argc
            ? VmGettext::coerceNullableDomainArg($frame->calledArgs[0], 'textdomain', 0, 'domain')
            : null;
        if (null === $frame->returnVar) {
            return;
        }
        VmGettext::writeStringOrFalseReturn(
            $frame->returnVar,
            VmGettextNative::textdomain($domain)
        );
    }
}
