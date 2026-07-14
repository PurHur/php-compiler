<?php

declare(strict_types=1);

// #18901 — proc_open(null) coerces to empty command like Zend Z_PARAM_ARRAY_HT_OR_STR.
$pipes = [];
$result = proc_open(null, [], $pipes);
echo 'is_resource=', (int) is_resource($result), "\n";
echo 'is_null=', (int) is_null($result), "\n";
if (is_resource($result)) {
    proc_close($result);
    exit(0);
}
exit(1);
