<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** _() — gettext() alias (php-src ext/gettext/gettext.c; issue #14966). */
final class _ extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('_');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCount($frame, 1);
        $msgid = VmGettext::msgidArgForFrame($frame, 0, '_', 0, 'msgid');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmGettextNative::gettext($msgid));
    }
}

