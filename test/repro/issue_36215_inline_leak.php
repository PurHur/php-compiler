<?php
for ($i = 0; $i < 200000; $i++) {
    $a = ['k'.$i => str_repeat('x', 100), 'n' => [$i, $i + 1]];
}
echo "done\n";
