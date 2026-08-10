<?php

$result = number_format(1.5, null);
if ('2' === $result) {
    echo "ok\n";
} else {
    echo 'fail: ', var_export($result, true), "\n";
}
