--TEST--
stdlib gettext phase 1 — bindtextdomain, textdomain, gettext msgid fallback (#3449)
--FILE--
<?php
echo extension_loaded('gettext') ? "gettext-loaded\n" : "gettext-missing\n";
$dir = bindtextdomain('messages', __DIR__);
echo is_string($dir) ? "bind-ok\n" : "bind-fail\n";
textdomain('messages');
echo gettext('Hello'), "\n";
echo dgettext('messages', 'World'), "\n";
--EXPECT--
gettext-loaded
bind-ok
Hello
World
