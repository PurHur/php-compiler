--TEST--
String offset with float index — cast warning and empty out-of-range read (issue #4166)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
$c = 'a'[1.5];
echo 'val:', $c, "\n";
$c2 = 'abc'[1.5];
echo 'mid:', $c2, "\n";
echo 'int:', 'abc'[1], "\n";
--EXPECT--
W:String offset cast occurred
W:Uninitialized string offset 1
val:
W:String offset cast occurred
mid:b
int:b
