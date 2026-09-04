<?php
// Nyholm Stream::$position is TYPE_VALUE under AOT (#36382).
$s = 'abcdef';
$pos = 2;
$pos = $pos + 0; // keep as runtime int box
echo substr_replace($s, 'XY', $pos, 2);
