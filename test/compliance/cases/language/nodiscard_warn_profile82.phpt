--TEST--
Language: #[\NoDiscard] unused return is silent on PROFILE=8.2 (#23038)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
ini_set('error_reporting', '32767');
error_clear_last();

#[\NoDiscard]
function compute(): int {
    return 42;
}

compute();
$last = error_get_last();
echo null === $last ? "none\n" : (($last['message'] ?? '')."\n");
echo "done\n";
--EXPECT--
none
done
