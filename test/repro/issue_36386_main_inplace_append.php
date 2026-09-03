<?php
// {main} $buf .= must keep prefix and length (#36386 / #36410)
$buf = 'ab';
$buf .= 'cd';
for ($i = 0; $i < 5; ++$i) {
    $buf .= 'x';
}
echo $buf, '|', strlen($buf), "\n";
