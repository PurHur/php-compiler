--TEST--
AOT: session_create_id() consecutive calls differ (#33023)
--FILE--
<?php
$a = session_create_id();
$b = session_create_id();
echo 'lena=', strlen($a), "\n";
echo 'lenb=', strlen($b), "\n";
echo 'uniq=', ($a !== $b) ? 'yes' : 'no', "\n";
--EXPECT--
lena=26
lenb=26
uniq=yes
