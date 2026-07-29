<?php
$s = 'é';
echo 'subj=', bin2hex($s), PHP_EOL;
preg_match('/./u', $s, $m);
echo 'dot_u=', bin2hex($m[0] ?? ''), PHP_EOL;
preg_match('/../', $s, $m2);
echo 'dotdot=', bin2hex($m2[0] ?? ''), PHP_EOL;
preg_match('/./u', $s, $m3, PREG_OFFSET_CAPTURE);
echo 'off=', bin2hex($m3[0][0] ?? ''), ',', (string) ($m3[0][1] ?? -1), PHP_EOL;
preg_match('/a./u', 'aé', $m5);
echo 'a_dot=', bin2hex($m5[0] ?? ''), PHP_EOL;
