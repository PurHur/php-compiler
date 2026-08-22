<?php
function g(&$r, $v)
{
    $r = $v;
}
$out = null;
g($out, 5);
echo $out, "\n";
$arr = [3, 1];
sort($arr);
echo $arr[0], '|', $arr[1];
