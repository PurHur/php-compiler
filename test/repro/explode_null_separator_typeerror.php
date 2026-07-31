<?php
declare(strict_types=1);

// #25942 — non-strict explode(null) is DEP+ValueError on Zend 8.2 (not TypeError).
// This file is strict_types: null separator must TypeError; empty still ValueError.
try {
    explode(null, 'a');
    echo "BUG: no exception\n";
} catch (TypeError $e) {
    echo "OK TypeError: ", $e->getMessage(), "\n";
} catch (\ValueError $e) {
    echo "BUG ValueError: ", $e->getMessage(), "\n";
}

try {
    explode('', 'a');
    echo "BUG: no exception\n";
} catch (\ValueError $e) {
    echo "OK ValueError: ", $e->getMessage(), "\n";
}
