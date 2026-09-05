<?php
declare(strict_types=1);
function f36382(?object $o = null): void {
    echo "eq:", (null === $o ? "1" : "0"), "\n";
    echo "is_null:", (is_null($o) ? "1" : "0"), "\n";
    echo "isset:", (isset($o) ? "1" : "0"), "\n";
    echo "class:", (is_object($o) ? get_class($o) : "notobj"), "\n";
}
echo "call_null\n";
f36382(null);
echo "call_omit\n";
f36382();
