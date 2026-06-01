<?php
echo "argc=", (int) isset($argc), " argv=", (int) isset($argv), "\n";
if (isset($argv)) {
    echo "count=", count($argv), "\n";
    echo "first=", (string) ($argv[0] ?? ''), "\n";
}

