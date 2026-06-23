<?php
// #10623 — str_getcsv() newline-only input (php-src parity)

$cases = [
    "\n" => [null],
    "\r\n" => [null],
    "\r" => [null],
    '' => [null],
];

$failed = 0;
foreach ($cases as $input => $want) {
    $got = str_getcsv($input);
    if ($got !== $want) {
        echo "FAIL input="; var_export($input); echo "\n";
        echo '  got:  '; var_export($got); echo "\n";
        echo '  want: '; var_export($want); echo "\n";
        ++$failed;
    }
}

exit($failed === 0 ? 0 : 1);
