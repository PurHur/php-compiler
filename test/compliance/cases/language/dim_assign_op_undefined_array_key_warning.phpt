--TEST--
Language: $a[missing] += 1 emits Undefined array key Warning then stores 1 (#31991, zend_vm_def.h)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$a = [];
$a['k'] += 1;
echo 'plus=', $a['k'], "\n";

$b = [];
$b['k']++;
echo 'inc=', $b['k'], "\n";

$c = [];
$c['x']['y'] += 1;
echo 'nest=', $c['x']['y'], "\n";

$d = [];
$d['k'] .= 'z';
echo 'dot=', $d['k'], "\n";

$e = null;
$e['k'] += 1;
echo 'null=', $e['k'], "\n";
--EXPECT--
W:Undefined array key "k"
plus=1
W:Undefined array key "k"
inc=1
W:Undefined array key "x"
W:Undefined array key "y"
nest=1
W:Undefined array key "k"
dot=z
W:Undefined array key "k"
null=1
