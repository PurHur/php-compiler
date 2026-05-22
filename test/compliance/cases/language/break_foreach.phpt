--TEST--
break exits foreach early (#115, #483; MiniWebApp route scan)
--FILE--
<?php
$found = false;
foreach ([1, 2, 3] as $v) {
    if ($v === 2) {
        break;
    }
    if ($v === 1) {
        continue;
    }
    $found = true;
}
echo $found ? "true\n" : "false\n";
--EXPECT--
false
