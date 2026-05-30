--TEST--
stdlib gethostname() JIT returns local hostname string
--FILE--
<?php
$h = gethostname();
if (strlen($h) < 1) {
    echo "false\n";
} else {
    echo "host\n";
    echo gethostname() === $h ? "stable\n" : "bad\n";
}
--EXPECT--
host
stable
