<?php
declare(strict_types=1);
$warnLine = __LINE__ + 1;
@$x = $undefined_var_maintainer_gap_13426;
$last = error_get_last();
if (($last['line'] ?? -1) !== $warnLine) {
    echo 'fail: line=', var_export($last['line'] ?? null, true), ' expected ', $warnLine, "\n";
    exit(1);
}
echo "ok\n";
