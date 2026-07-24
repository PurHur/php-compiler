--TEST--
String offset null/bool/float index — cast warning then coerce (issue #22896, re-#4166)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
$s = 'ab';
$s[null] = 'c';
echo 'null:', $s, "\n";
$s = 'ab';
$s[false] = 'X';
echo 'false:', $s, "\n";
$s = 'ab';
$s[true] = 'Z';
echo 'true:', $s, "\n";
$s = 'ab';
$s[1.7] = 'Q';
echo 'float:', $s, "\n";
$c = 'abc'[null];
echo 'read-null:', $c, "\n";
$c = 'abc'[true];
echo 'read-true:', $c, "\n";
--EXPECT--
W:String offset cast occurred
null:cb
W:String offset cast occurred
false:Xb
W:String offset cast occurred
true:aZ
W:String offset cast occurred
float:aQ
W:String offset cast occurred
read-null:a
W:String offset cast occurred
read-true:b
