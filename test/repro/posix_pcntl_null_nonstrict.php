<?php
// Non-strict mode: null should coerce (with possible deprecation), not TypeError
error_reporting(E_ALL);

// posix_kill(null, 0) should coerce null→0 and succeed (kill(0,0) is valid)
$result = @posix_kill(null, 0);
echo "posix_kill(null,0) = " . var_export($result, true) . "\n";

// posix_getpwuid(null) should coerce null→0 and lookup root
$result = @posix_getpwuid(null);
echo "posix_getpwuid(null) = " . (is_array($result) ? "array(name={$result['name']})" : var_export($result, true)) . "\n";

// pcntl_alarm(null) should coerce null→0
$result = @pcntl_alarm(null);
echo "pcntl_alarm(null) = " . var_export($result, true) . "\n";

echo "OK\n";
