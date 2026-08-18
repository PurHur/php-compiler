<?php
// AOT lint: exec/passthru/system named result_code (#23625, ext/standard/exec.c).
$rc = 0;
echo exec(command: 'true', result_code: $rc), "\n";
passthru(command: 'true', result_code: $rc);
system(command: 'true', result_code: $rc);
