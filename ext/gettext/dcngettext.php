<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;

/** dcngettext() — plural translate with locale category (php-src ext/gettext/gettext.c; #6608). */
final class dcngettext extends GettextFunction
{
    public function __construct()
    {
        parent::__construct('dcngettext');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(sprintf(
                'dcngettext() expects at most 5 arguments, %d given',
                $argc
            ));
        }
        $domain = VmGettext::domainArgForFrame($frame, 0, 'dcngettext', 0, 'domain');
        $msgid1 = VmGettext::msgidArgForFrame($frame, 1, 'dcngettext', 1, 'msgid1');
        $msgid2 = VmGettext::msgidArgForFrame($frame, 2, 'dcngettext', 2, 'msgid2');
        $n = VmGettext::coerceCountArg($frame->calledArgs[3], 'dcngettext', 3, 'count');
        $category = 5 === $argc
            ? VmGettext::coerceCategoryArg($frame->calledArgs[4], 'dcngettext', 4, 'category')
            : VmGettextNative::defaultCategory();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmGettextNative::dcngettext($domain, $msgid1, $msgid2, $n, $category));
    }
}
