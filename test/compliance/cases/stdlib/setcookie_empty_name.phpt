--TEST--
stdlib setcookie()/setrawcookie() empty name — ValueError (#11945, ext/standard/head.c)
--FILE--
<?php
foreach (['setcookie', 'setrawcookie'] as $fn) {
    try {
        $fn('');
        echo "unexpected_success {$fn}\n";
    } catch (ValueError $e) {
        echo $fn, " ValueError\n";
    }
}
--EXPECT--
setcookie ValueError
setrawcookie ValueError
