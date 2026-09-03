<?php
// @differential-repeat: 3 dynamic $buf .= must stay Zend-identical under AOT (#36386)
$buf = '';
for ($i = 0; $i < 40; $i++) {
    $buf .= 'row-'.$i.';';
}
echo strlen($buf), '|', substr($buf, 0, 14), '|', substr($buf, 262, 8), "\n";
