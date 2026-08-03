--TEST--
AOT mb_ucfirst()/mb_lcfirst() — UTF-8 first character case (#27330, #17609)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo mb_ucfirst('äbc'), "\n";
echo mb_lcfirst('Äbc'), "\n";
echo mb_ucfirst('über'), "\n";
echo mb_lcfirst('Über'), "\n";
--EXPECT--
Äbc
äbc
Über
über
