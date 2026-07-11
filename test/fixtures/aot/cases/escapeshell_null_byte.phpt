--TEST--
AOT: escapeshellarg()/escapeshellcmd() reject embedded NUL (#12497)
--FILE--
<?php
try {
    escapeshellarg("a\0b");
    echo "arg accepted\n";
} catch (ValueError $e) {
    echo "arg rejected\n";
}
try {
    escapeshellcmd("a\0b");
    echo "cmd accepted\n";
} catch (ValueError $e) {
    echo "cmd rejected\n";
}
--EXPECT--
arg rejected
cmd rejected
