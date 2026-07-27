<?php
foreach (["str" => "secret-value", "int" => 42, "null" => null, "true" => true, "arr" => [1]] as $label => $v) {
  try { match ($v) { 0 => 0, "nope" => 1 }; echo "$label:no\n"; }
  catch (UnhandledMatchError $e) { echo "$label:", $e->getMessage(), "\n"; }
}
ini_set("zend.exception_string_param_max_len", "0");
try { match ("secret-value") { 0 => 0 }; } catch (UnhandledMatchError $e) { echo "str0:", $e->getMessage(), "\n"; }
