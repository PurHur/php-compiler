<?php
// AOT compile-only (#9921): DateTime::createFromFormat() mutable class VM builtin.
$dt = DateTime::createFromFormat('Y-m-d', '2024-06-05');
var_export($dt !== false);
$di = DateTimeImmutable::createFromFormat('Y-m-d', '2024-06-05');
var_export($di !== false);
