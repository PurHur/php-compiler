<?php

$s = microtime(null);
if (!\is_string($s) || \strlen($s) < 10) {
    echo "bad\n";
    exit(1);
}
echo "ok\n";
