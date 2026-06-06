--TEST--
Language: #[\Deprecated] on enum case — fetch emits E_USER_DEPRECATED (#6921)
--FILE--
<?php
ini_set('error_reporting', '32767');
set_error_handler(function (): bool {
    return true;
});

enum E {
    #[\Deprecated]
    case Test;

    #[\Deprecated(message: 'use E::Test instead')]
    case Test2;
}

E::Test;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";

E::Test2;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
--EXPECT--
Enum case E::Test is deprecated
Enum case E::Test2 is deprecated, use E::Test instead
