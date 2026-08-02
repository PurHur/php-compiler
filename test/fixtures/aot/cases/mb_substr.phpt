--TEST--
AOT: mb_substr() UTF-8 character substring (#27028)
--FILE--
<?php
echo mb_substr('abcdef', 2, 2), "\n";
$s = 'café';
echo mb_substr($s, 0, 2, 'UTF-8'), "\n";
echo mb_substr($s, 1, 2, 'UTF-8'), "\n";
echo mb_substr('αβγ', -2, null, 'UTF-8'), "\n";
echo mb_substr('αβγ', 0, -1, 'UTF-8'), "\n";
--EXPECT--
cd
ca
af
βγ
αβ
