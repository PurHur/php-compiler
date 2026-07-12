--TEST--
stream_set_blocking($pipes[N], false) — dim-fetch + hoisted false ConstFetch arg slots (#18186, ext/standard/streamsfuncs.c)
--FILE--
<?php
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open('true', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
var_export(stream_set_blocking($pipes[1], false));
echo "\n";
var_export(stream_set_blocking($pipes[2], false));
echo "\n";
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
?>
--EXPECT--
true
true
