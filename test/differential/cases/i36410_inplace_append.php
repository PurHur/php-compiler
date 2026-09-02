<?php
// @differential-repeat: 3 in-place append must stay correct under AOT (#36410)
$s = '';
for ($i = 0; $i < 100; $i++) {
    $s .= 'x';
}
echo strlen($s), "\n";
