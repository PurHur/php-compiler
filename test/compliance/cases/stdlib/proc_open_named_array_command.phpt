--TEST--
stdlib proc_open() command:/descriptor_spec:/pipes:/cwd: named parameters (#11170, ext/standard/exec.c)
--FILE--
<?php
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open(
    command: ['echo', 'hi'],
    descriptor_spec: $desc,
    pipes: $pipes,
    cwd: null,
);
if (!is_resource($proc)) {
    echo "fail\n";
    exit(1);
}
$out = stream_get_contents($pipes[1]);
fclose($pipes[1]);
proc_close($proc);
echo trim($out) === 'hi' ? "ok\n" : "fail\n";
--EXPECT--
ok
