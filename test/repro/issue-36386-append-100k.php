<?php
$buf = '';
for ($i = 0; $i < 100000; ++$i) {
    $buf .= 'x';
}
echo strlen($buf), "\n";
