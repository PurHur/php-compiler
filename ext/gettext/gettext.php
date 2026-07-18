<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** gettext() — translate msgid in current text domain (php-src ext/gettext/gettext.c; #3449). */
final class gettext extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('gettext');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCount($frame, 1);
        $msgid = VmGettext::msgidArgForFrame($frame, 0, 'gettext', 0, 'msgid');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmGettextNative::gettext($msgid));
    }
}
