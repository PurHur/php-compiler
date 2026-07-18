<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** dcgettext() — domain + locale category translate (php-src ext/gettext/gettext.c; #6608). */
final class dcgettext extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('dcgettext');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'dcgettext() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $domain = VmGettext::domainArgForFrame($frame, 0, 'dcgettext', 0, 'domain');
        $msgid = VmGettext::msgidArgForFrame($frame, 1, 'dcgettext', 1, 'message');
        $category = 3 === $argc
            ? VmGettext::coerceCategoryArg($frame->calledArgs[2], 'dcgettext', 2, 'category')
            : VmGettextNative::defaultCategory();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmGettextNative::dcgettext($domain, $msgid, $category));
    }
}
