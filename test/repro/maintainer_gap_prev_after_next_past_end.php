<?php

$a = [1, 2, 3];
end($a);
next($a);
$r = prev($a);
if (false !== $r) {
    echo 'fail: prev() returned ', var_export($r, true), " not false\n";
    exit(1);
}
echo "ok\n";
