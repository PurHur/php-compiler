--TEST--
Language: bare #[\Deprecated] on properties emits E_USER_DEPRECATED on write (#23536)
--FILE--
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');

class C {
    #[\Deprecated]
    public int $p = 2;
}

$c = new C();
$c->p = 9;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "write\n" : "no-write\n";
echo $c->p, "\n";
--EXPECT--
Property C::$p is deprecated
write
9
