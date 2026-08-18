<?php
$rc = 0;
echo exec(command: 'printf hi', result_code: $rc), "\n";
passthru(command: 'true', result_code: $rc);
system(command: 'true', result_code: $rc);
