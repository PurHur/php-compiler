--TEST--
stdlib gettext() bindtextdomain — pure PHP MO reader without libintl FFI (#8952)
--FILE--
<?php
$root = dirname(__DIR__, 3).'/fixtures/gettext/messages';
bindtextdomain('messages', $root);
textdomain('messages');
echo gettext('Hello'), "\n";
echo dgettext('messages', 'World'), "\n";
echo gettext('missing'), "\n";
--EXPECT--
Hola
Mundo
missing
