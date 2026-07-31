--TEST--
Language: match/switch case expressions emit Undefined variable E_WARNING (Zend/zend_execute.c, #26147)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$x = 1;
$result = match ($x) {
    $undefined => 'u',
    1 => 'one',
    default => 'd',
};
echo "match=$result\n";
switch ($x) {
    case $undefined2:
        echo "switch=u\n";
        break;
    case 1:
        echo "switch=one\n";
        break;
}
--EXPECT--
W:Undefined variable $undefined
match=one
W:Undefined variable $undefined2
switch=one
