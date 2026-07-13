<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$result = @getimagesizefromstring('not-an-image');
$last = error_get_last();
if (false !== $result) {
    echo "fail: expected false\n";
    exit(1);
}
if (null !== $last) {
    echo 'fail: unexpected error '.var_export($last, true)."\n";
    exit(1);
}

echo "ok\n";
