--TEST--
stdlib compact() — forward-declared local omitted until assign (#10164, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

function compact_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}

enum E: int {
    case A = 1;
}

set_error_handler('compact_warn_capture');
var_export(compact('e'));
$e = E::A;
var_export(compact('e'));
echo "\n";
--EXPECT--
W:compact(): Undefined variable $e
array (
)array (
  'e' => 
  \E::A,
)
