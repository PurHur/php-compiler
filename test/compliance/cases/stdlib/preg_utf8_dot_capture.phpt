--TEST--
stdlib preg /./u captures full UTF-8 codepoint (issue #24785, ext/pcre / VmPregEngine)
--FILE--
<?php
$s = 'é';
echo 'subj=', bin2hex($s), "\n";
preg_match('/./u', $s, $m);
echo 'dot_u=', bin2hex($m[0] ?? ''), "\n";
preg_match('/../', $s, $m2);
echo 'dotdot=', bin2hex($m2[0] ?? ''), "\n";
preg_match('/./u', $s, $m3, PREG_OFFSET_CAPTURE);
echo 'off=', bin2hex($m3[0][0] ?? ''), ',', (string) ($m3[0][1] ?? -1), "\n";
preg_match('/./u', 'aé', $m4);
echo 'mid=', bin2hex($m4[0] ?? ''), "\n";
preg_match('/a./u', 'aé', $m5);
echo 'a_dot=', bin2hex($m5[0] ?? ''), "\n";
--EXPECT--
subj=c3a9
dot_u=c3a9
dotdot=c3a9
off=c3a9,0
mid=61
a_dot=61c3a9
