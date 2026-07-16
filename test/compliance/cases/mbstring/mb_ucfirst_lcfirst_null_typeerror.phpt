--TEST--
mbstring mb_ucfirst()/mb_lcfirst() null $string — TypeError on 8.4 profile (#19433, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['mb_ucfirst', 'mb_lcfirst'] as $f) {
    try {
        $f(null);
        echo "$f: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo mb_ucfirst('über'), "\n";
--EXPECT--
mb_ucfirst(): Argument #1 ($string) must be of type string, null given
mb_lcfirst(): Argument #1 ($string) must be of type string, null given
Über
