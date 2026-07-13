--TEST--
Stdlib: proc_get_status() cached key absent on forward profile (#18731, re-#17883, php-src-strict)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('true', $desc, $pipes);
fclose($pipes[1]);
fclose($pipes[2]);
for ($i = 0; $i < 50; ++$i) {
    $status = proc_get_status($proc);
    if (!$status['running']) {
        break;
    }
    usleep(10000);
}
echo array_key_exists('cached', $status) ? "present\n" : "absent\n";
echo $status['running'] ? "running\n" : "done\n";
proc_close($proc);
--EXPECT--
absent
done
