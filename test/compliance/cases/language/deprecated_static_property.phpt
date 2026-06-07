--TEST--
Language: #[\Deprecated] on static properties emits E_USER_DEPRECATED on read/write (#7369)
--FILE--
<?php
ini_set('error_reporting', '32767');

class C {
    #[\Deprecated(since: '8.4', message: 'legacy')]
    public static int $x = 1;
}

C::$x;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";

C::$x = 2;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
--EXPECTF--
%aProperty C::$x is deprecated since 8.4, legacy
Property C::$x is deprecated since 8.4, legacy
