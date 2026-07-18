--TEST--
stdlib gettext/_/dgettext(null) TypeError on 8.4 forward profile (#20209, ext/gettext/gettext.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['gettext', '_'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ': COERCED ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
try {
    $r = dgettext('messages', null);
    echo 'dgettext: COERCED ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo 'dgettext: ', $e->getMessage(), "\n";
}
echo 'ok_string=', gettext('hello'), "\n";
?>
--EXPECT--
gettext: gettext(): Argument #1 ($msgid) must be of type string, null given
_: _(): Argument #1 ($msgid) must be of type string, null given
dgettext: dgettext(): Argument #2 ($message) must be of type string, null given
ok_string=hello
