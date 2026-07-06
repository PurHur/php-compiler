--TEST--
stdlib mbstring encoding: named parameter (#16885, ext/mbstring/mbstring.stub.php)
--FILE--
<?php
echo mb_substr('hello', 0, 2, encoding: 'UTF-8'), "\n";
echo mb_strimwidth('hello', 0, 3, '..', encoding: 'UTF-8'), "\n";
echo mb_strcut('hello', 0, 3, encoding: 'UTF-8'), "\n";
echo mb_stripos('Hello', 'll', encoding: 'UTF-8'), "\n";
?>
--EXPECT--
he
h..
hel
2
