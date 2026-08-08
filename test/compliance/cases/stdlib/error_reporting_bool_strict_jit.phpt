--TEST--
stdlib error_reporting() JIT — bool error_level TypeError under strict_types (#17038)
--FILE--
<?php
declare(strict_types=1);

try {
    error_reporting(false);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$prev = error_reporting(0);
echo $prev === 22527 ? "old-level\n" : "old-bad\n";
--EXPECT--
error_reporting(): Argument #1 ($error_level) must be of type ?int, false given
old-level
