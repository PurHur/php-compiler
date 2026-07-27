--TEST--
Language: #[\Deprecated] on enum case — fetch emits E_USER_DEPRECATED (#6921)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');

enum E {
    #[\Deprecated]
    case Test;

    #[\Deprecated(message: 'use E::Test instead')]
    case Test2;
}

E::Test;
$last = error_get_last();
echo ($last['type'] ?? 0) === 16384 ? "bare\n" : "no-bare\n";

E::Test2;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
--EXPECT--
no-bare
Enum case E::Test2 is deprecated, use E::Test instead
