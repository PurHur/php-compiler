<?php
// #31170 — createFromFormat `+` trailing junk is a warning, not a dropped last-errors bag.
$d = DateTime::createFromFormat('Y-m-d+', '2024-01-02x');
var_export($d === false);
echo "\n";
echo json_encode(DateTime::getLastErrors()), "\n";
