<?php
// AOT: packed TYPE_NULL must survive array_* copy/walk (#33699).
$a = [null, 1];
echo 'merge=', count(array_merge($a, [2])), "\n";
echo 'values=', serialize(array_values($a)), "\n";
echo 'pad=', serialize(array_pad([null], 3, 'x')), "\n";
echo 'replace=', serialize(array_replace($a, [1 => 9])), "\n";
echo 'unique=', serialize(array_unique([null, null, 1])), "\n";
echo 'reverse=', serialize(array_reverse($a)), "\n";
echo 'ckc=', serialize(array_change_key_case($a)), "\n";
echo 'combine=', serialize(array_combine([0, 1], [null, 2])), "\n";
