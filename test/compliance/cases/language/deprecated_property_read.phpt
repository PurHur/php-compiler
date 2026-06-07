--TEST--
Language: #[\Deprecated] on properties emits E_USER_DEPRECATED on read (#7369)
--FILE--
<?php
ini_set('error_reporting', '32767');

class C {
    #[\Deprecated(message: 'old prop', since: '8.4')]
    public int $x = 1;
}

$c = new C();
$c->x;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "dep\n" : "no\n";
--EXPECTF--
%aProperty C::$x is deprecated since 8.4, old prop
dep
