--TEST--
AOT connection_status() CLI returns CONNECTION_NORMAL (issues #6161, #7234)
--FILE--
<?php
$st = connection_status();
if (is_object($st)) {
    echo $st->value, "\n";
    echo $st->value === CONNECTION_NORMAL ? "match\n" : "bad\n";
} else {
    echo $st, "\n";
    echo $st === CONNECTION_NORMAL ? "match\n" : "bad\n";
}
--EXPECT--
0
match
