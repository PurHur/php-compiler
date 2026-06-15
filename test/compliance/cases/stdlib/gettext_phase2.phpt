--TEST--
stdlib gettext phase 2 — dcgettext, dngettext, bind_textdomain_codeset (#6608)
--FILE--
<?php
bindtextdomain('messages', __DIR__);
echo dcgettext('messages', 'Hi', 5), "\n";
echo dngettext('messages', 'item', 'items', 1), "\n";
echo dngettext('messages', 'item', 'items', 2), "\n";
$cs = bind_textdomain_codeset('messages', 'UTF-8');
echo is_string($cs) ? "codeset-ok\n" : "codeset-fail\n";
--EXPECT--
Hi
item
items
codeset-ok
