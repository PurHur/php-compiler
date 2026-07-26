--TEST--
Language: bare #[\Deprecated] on properties emits E_USER_DEPRECATED on read (#23536)
--FILE--
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');

class C {
    #[\Deprecated]
    public int $p = 2;
    #[\Deprecated(message: 'msg')]
    public int $q = 3;
}

$c = new C();
$c->p;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "bare\n" : "no-bare\n";

$c->q;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "msg\n" : "no-msg\n";
--EXPECT--
Property C::$p is deprecated
bare
Property C::$q is deprecated, msg
msg
