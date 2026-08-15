<?php
// #31169 — createFromFormat `!` is a reset modifier; trailing junk → slot 10 Trailing data.
$d = DateTime::createFromFormat('Y-m-d!', '2024-01-02x');
var_export($d === false);
echo "\n";
echo json_encode(DateTime::getLastErrors()), "\n";
$ok = DateTime::createFromFormat('Y-m-d!', '2020-01-15');
echo $ok ? $ok->format('Y-m-d H:i:s') : 'false', "\n";
