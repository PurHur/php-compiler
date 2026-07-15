<?php

$result = number_format(null);
if ('0' === $result) {
    echo "ok\n";
} else {
    echo 'fail: ', var_export($result, true), "\n";
}
