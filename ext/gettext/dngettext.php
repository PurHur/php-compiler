<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** dngettext() — plural translate in named domain (php-src ext/gettext/gettext.c; #6608). */
final class dngettext extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('dngettext');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCount($frame, 4);
        $domain = VmGettext::domainArgForFrame($frame, 0, 'dngettext', 0, 'domain');
        $msgid1 = VmGettext::msgidArgForFrame($frame, 1, 'dngettext', 1, 'msgid1');
        $msgid2 = VmGettext::msgidArgForFrame($frame, 2, 'dngettext', 2, 'msgid2');
        $n = VmGettext::coerceCountArg($frame->calledArgs[3], 'dngettext', 3, 'count');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmGettextNative::dngettext($domain, $msgid1, $msgid2, $n));
    }
}
