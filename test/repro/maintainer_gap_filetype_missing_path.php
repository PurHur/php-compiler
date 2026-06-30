<?php

declare(strict_types=1);

error_clear_last();
$ret = @filetype('/no/such/path');
var_dump($ret);

$last = error_get_last();
if (null === $last) {
    echo "error_get_last: NULL\n";
} else {
    echo "error_get_last: {$last['message']}\n";
}

