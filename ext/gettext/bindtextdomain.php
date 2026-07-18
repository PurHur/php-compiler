<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** bindtextdomain() — bind .mo catalog directory for domain (php-src ext/gettext/gettext.c; #3449). */
final class bindtextdomain extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('bindtextdomain');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'bindtextdomain() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        $domain = VmGettext::domainArgForFrame($frame, 0, 'bindtextdomain', 0, 'domain');
        $directory = 2 === $argc
            ? VmGettext::coerceNullableDirectoryArg($frame->calledArgs[1], 'bindtextdomain', 1, 'directory')
            : null;
        if (null === $frame->returnVar) {
            return;
        }
        VmGettext::writeStringOrFalseReturn(
            $frame->returnVar,
            VmGettextNative::bindtextdomain($domain, $directory)
        );
    }
}
