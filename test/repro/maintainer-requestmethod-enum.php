<?php
// Repro for #28931 / re-#7230 — RequestMethod phantom absent.

echo 'RequestMethod enum: ', enum_exists('RequestMethod', false) ? 'yes' : 'no', "\n";
if (enum_exists('RequestMethod', false)) {
    fwrite(STDERR, "FAIL: RequestMethod phantom still registered\n");
    exit(1);
}
echo "absent_ok\n";
