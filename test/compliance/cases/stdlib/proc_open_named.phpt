--TEST--
stdlib proc_open() command:/descriptor_spec:/pipes: named parameters (#10126, ext/standard/proc_open.c)
--FILE--
<?php
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open(command: 'echo ok', descriptor_spec: $desc, pipes: $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$out = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$code = proc_close($proc);
echo trim($out), ':', $code, "\n";
--EXPECT--
ok:0
