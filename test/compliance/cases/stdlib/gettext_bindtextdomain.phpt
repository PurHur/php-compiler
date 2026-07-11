--TEST--
stdlib gettext() bindtextdomain — pure PHP MO reader without libintl FFI (#8952)
--FILE--
<?php
// PHPUnit pipes FILE via stdin so __DIR__ is repo root, not this .phpt path (#17086).
$root = dirname(__DIR__, 3).'/fixtures/gettext/messages';
if (!is_dir($root)) {
    $root = __DIR__.'/test/fixtures/gettext/messages';
}
$root = realpath($root) ?: $root;
bindtextdomain('messages', $root);
textdomain('messages');
echo gettext('Hello'), "\n";
echo dgettext('messages', 'World'), "\n";
echo gettext('missing'), "\n";
--EXPECT--
Hola
Mundo
missing
