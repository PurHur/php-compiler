--TEST--
continue skips foreach iteration (#115, #483)
--FILE--
<?php
$s = '';
foreach ([1, 2, 3, 4] as $v) {
    if ($v === 2) {
        continue;
    }
    $s = $s . $v;
}
echo $s, "\n";
--EXPECT--
134
