--TEST--
stdlib proc_open() inline nested descriptor_spec (#11485, ext/standard/proc_open.c)
--FILE--
<?php
declare(strict_types=1);
$pipes = [];
$proc = proc_open(
    'true',
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
var_export(\is_resource($proc));
echo "\n";
var_export(\count($pipes));
echo "\n";
foreach ($pipes as $pipe) {
    if (\is_resource($pipe)) {
        fclose($pipe);
    }
}
if (\is_resource($proc)) {
    proc_close($proc);
}
?>
--EXPECT--
true
3
