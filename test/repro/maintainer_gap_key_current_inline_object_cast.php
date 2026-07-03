<?php

$k = key((object) []);
if (null !== $k) {
    echo 'fail: key returned ', var_export($k, true), "\n";
    exit(1);
}

$c = current((object) []);
if (false !== $c) {
    echo 'fail: current returned ', var_export($c, true), "\n";
    exit(1);
}

echo "ok\n";
