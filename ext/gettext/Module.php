<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\ModuleAbstract;

/**
 * gettext extension module entry (php-src ext/gettext/gettext.c; #3449, #6608).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new gettext(),
            new _(),
            new bindtextdomain(),
            new textdomain(),
            new dgettext(),
            new dcgettext(),
            new dngettext(),
            new dcngettext(),
            new bind_textdomain_codeset(),
        ];
    }
}
