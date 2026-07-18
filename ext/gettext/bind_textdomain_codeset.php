<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** bind_textdomain_codeset() — set/get domain codeset (php-src ext/gettext/gettext.c; #6608). */
final class bind_textdomain_codeset extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('bind_textdomain_codeset');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'bind_textdomain_codeset() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        $domain = VmGettext::domainArgForFrame($frame, 0, 'bind_textdomain_codeset', 0, 'domain');
        $codeset = 2 === $argc
            ? VmGettext::coerceNullableDirectoryArg($frame->calledArgs[1], 'bind_textdomain_codeset', 1, 'codeset')
            : null;
        if (null === $frame->returnVar) {
            return;
        }
        VmGettext::writeStringOrFalseReturn(
            $frame->returnVar,
            VmGettextNative::bindTextdomainCodeset($domain, $codeset)
        );
    }
}
