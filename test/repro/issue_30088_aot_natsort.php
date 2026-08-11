<?php
$a = ['img12.png', 'img10.png', 'img2.png'];
natsort($a);
echo implode(',', $a), "\n";
echo strnatcmp('img2', 'img10'), "\n";
$b = ['A2', 'a10', 'A1'];
natcasesort($b);
echo implode(',', $b), "\n";
