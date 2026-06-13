--TEST--
AOT: date()/gmdate() ArgumentCountError/TypeError + format coercion (#4496)
--FILE--
<?php
class S { public function __toString(): string { return "Y-m-d"; } }
$ts = 946684800;

echo date(null) === "" ? "date_null_ok\n" : "date_null_bad\n";
echo date(new S(), $ts), "\n";

try { date("Y-m-d", "not-an-int"); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { date(); } catch (ArgumentCountError $e) { echo $e->getMessage(), "\n"; }

echo gmdate(null) === "" ? "gmdate_null_ok\n" : "gmdate_null_bad\n";
echo gmdate(new S(), $ts), "\n";

try { gmdate("Y-m-d", "not-an-int"); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { gmdate(); } catch (ArgumentCountError $e) { echo $e->getMessage(), "\n"; }
--EXPECT--
date_null_ok
2000-01-01
date(): Argument #2 ($timestamp) must be of type ?int, string given
date() expects at least 1 argument, 0 given
gmdate_null_ok
2000-01-01
gmdate(): Argument #2 ($timestamp) must be of type ?int, string given
gmdate() expects at least 1 argument, 0 given
