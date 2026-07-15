<?php

$result = @gethostbyname(null);
if ('' === $result) {
    echo "ok\n";
} else {
    echo 'fail: ', var_export($result, true), "\n";
}
