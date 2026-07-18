<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** dgettext() — translate msgid in named domain (php-src ext/gettext/gettext.c; #3449). */
final class dgettext extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('dgettext');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCount($frame, 2);
        $domain = VmGettext::domainArgForFrame($frame, 0, 'dgettext', 0, 'domain');
        $msgid = VmGettext::msgidArgForFrame($frame, 1, 'dgettext', 1, 'message');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmGettextNative::dgettext($domain, $msgid));
    }
}
