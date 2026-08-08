--TEST--
Language: dim ??= / []= on undefined CV quiet autovivify — no Undefined variable (#29146, zend_execute.c)
--FILE--
<?php
error_reporting(E_ALL);

$a["x"] ??= 1;
var_export($a);
echo "\n";

$b["k"] = "y";
var_export($b);
echo "\n";

// Bare undefined read still warns (control).
$out = @($undef + 0);
echo "bare=", $out, "\n";
$last = error_get_last();
echo (is_array($last) && str_contains($last["message"] ?? "", "Undefined variable")) ? "bare-warned\n" : "bare-silent\n";
--EXPECT--
array (
  'x' => 1,
)
array (
  'k' => 'y',
)
bare=0
bare-warned
