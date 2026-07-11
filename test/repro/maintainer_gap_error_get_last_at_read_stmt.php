<?php

declare(strict_types=1);

@$undefined_maintainer_gap_13587;
$last = error_get_last();
if (!\is_array($last)) {
    echo 'fail: expected array, got ', var_export($last, true), "\n";
    exit(1);
}
if (!str_contains($last['message'] ?? '', 'Undefined variable')) {
    echo 'fail: message=', var_export($last['message'] ?? null, true), "\n";
    exit(1);
}
echo "ok\n";
