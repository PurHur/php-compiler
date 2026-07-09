--TEST--
Language: array unpack string key — undefined key variable warns before empty-key dim (#17483)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$a = ['x' => 1];
[$k => $v] = $a;
echo "done\n";
--EXPECT--
W:Undefined variable $k
W:Undefined array key ""
done
