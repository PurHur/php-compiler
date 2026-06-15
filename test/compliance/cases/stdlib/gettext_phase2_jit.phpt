--TEST--
stdlib gettext phase 2 — JIT dcgettext, dngettext, bind_textdomain_codeset (#6608, #8625)
--FILE--
<?php
bindtextdomain('messages', '/tmp');
bind_textdomain_codeset('messages', 'UTF-8');
echo dcgettext('messages', 'Hi', 5), "\n";
echo dngettext('messages', 'item', 'items', 1), "\n";
echo dngettext('messages', 'item', 'items', 2), "\n";
--EXPECT--
Hi
item
items
