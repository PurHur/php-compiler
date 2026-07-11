--TEST--
stdlib proc_close() — exit code after non-blocking drain + early pipe close (#14685, ext/standard/proc_open.c)
--FILE--
<?php
declare(strict_types=1);
$desc = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
while ('' !== ($chunk = (string) stream_get_contents($pipes[1])) || '' !== ($chunk2 = (string) stream_get_contents($pipes[2]))) {
    if ('' === $chunk && '' === $chunk2) {
        break;
    }
}
fclose($pipes[1]);
fclose($pipes[2]);
$st = proc_get_status($proc);
echo ($st['running'] ? 'running' : 'stopped'), "\n";
echo 'closed=', proc_close($proc), "\n";
--EXPECT--
running
closed=1
