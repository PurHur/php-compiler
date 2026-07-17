--TEST--
stdlib mb_strstr() — multibyte strstr (#19941, ext/mbstring/mbstring.c)
--FILE--
<?php
var_dump(mb_strstr('Hello World', 'World'));
var_dump(mb_strstr('Hello World', 'World', true));
var_dump(mb_strstr('Hello World', 'xyz'));
var_dump(mb_strstr("caf\xC3\xA9 r\xC3\xA9sum\xC3\xA9", "\xC3\xA9"));
var_dump(mb_strstr("caf\xC3\xA9 r\xC3\xA9sum\xC3\xA9", "\xC3\xA9", true));
--EXPECT--
string(5) "World"
string(6) "Hello "
bool(false)
string(11) "é résumé"
string(3) "caf"
