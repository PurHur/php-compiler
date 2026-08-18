--TEST--
Language: keyed list destructure Undefined array key Warning cites list site (#31994, zend_vm_def.h)
--FILE--
<?php
function warn_line(int $errno, string $message, string $file, int $line): bool
{
    echo 'W:', $message, '@', $line, "\n";

    return true;
}
set_error_handler('warn_line');

[$a, $b] = [1];
echo "a=$a\n";
['x' => $c] = ['y' => 1];
echo "c_done\n";
--EXPECT--
W:Undefined array key 1@10
a=1
W:Undefined array key "x"@12
c_done
