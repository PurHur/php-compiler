<?php
$subject = ['a1', 'b2'];
$r = str_replace('1', 'X', $subject);
echo $r[0], "\n";
echo $r[1], "\n";
$ir = str_ireplace('A', 'b', ['xA', 'yb']);
echo $ir[0], "\n";
echo $ir[1], "\n";
