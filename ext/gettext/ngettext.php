<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** ngettext() — plural translate in current text domain (php-src ext/gettext/gettext.c; #14976). */
final class ngettext extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('ngettext');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCount($frame, 3);
        $msgid1 = VmGettext::msgidArgForFrame($frame, 0, 'ngettext', 0, 'msgid1');
        $msgid2 = VmGettext::msgidArgForFrame($frame, 1, 'ngettext', 1, 'msgid2');
        $n = VmGettext::coerceCountArg($frame->calledArgs[2], 'ngettext', 2, 'count');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmGettextNative::ngettext($msgid1, $msgid2, $n));
    }
}
