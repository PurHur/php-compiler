<?php

declare(strict_types=1);

$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipesVar = [];
$procVar = proc_open('true', $desc, $pipesVar);
if (!\is_resource($procVar)) {
    echo "fail: proc_open_var_spec\n";
    exit(1);
}
foreach ($pipesVar as $pipe) {
    if (\is_resource($pipe)) {
        fclose($pipe);
    }
}
proc_close($procVar);

$pipesInline = [];
$procInline = proc_open(
    'true',
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipesInline
);
if (!\is_resource($procInline)) {
    echo "fail: proc_open_inline_spec\n";
    exit(1);
}
foreach ($pipesInline as $pipe) {
    if (\is_resource($pipe)) {
        fclose($pipe);
    }
}
proc_close($procInline);

echo "proc_open_var_spec_ok\n";
echo "proc_open_inline_spec_ok\n";
