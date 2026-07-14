<?php

// #18922 — exec family null must ValueError on 8.4 forward profile, not TypeError.
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_exec_null_valueerror_84.php

foreach (['exec', 'shell_exec', 'system', 'passthru'] as $fn) {
    try {
        $fn(null);
        fwrite(STDERR, "$fn(null): expected ValueError\n");
        exit(1);
    } catch (ValueError) {
        echo "$fn(null): ok\n";
    } catch (TypeError) {
        fwrite(STDERR, "$fn(null): got TypeError, expected ValueError\n");
        exit(1);
    }
}

try {
    $r = popen(null, 'r');
    if (!\is_resource($r)) {
        fwrite(STDERR, 'popen(null): expected resource, got '.var_export($r, true)."\n");
        exit(1);
    }
    pclose($r);
    echo "popen(null): ok\n";
} catch (TypeError) {
    fwrite(STDERR, 'popen(null): got TypeError, expected resource'."\n");
    exit(1);
}

try {
    scandir(null);
    fwrite(STDERR, "scandir(null): expected ValueError\n");
    exit(1);
} catch (ValueError) {
    echo "scandir(null): ok\n";
} catch (TypeError) {
    fwrite(STDERR, "scandir(null): got TypeError, expected ValueError\n");
    exit(1);
}
