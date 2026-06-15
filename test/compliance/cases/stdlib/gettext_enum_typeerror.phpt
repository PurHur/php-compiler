--TEST--
stdlib gettext() — enum case msgid TypeError (php-src-strict, #5780)
--FILE--
<?php
enum Es: string { case Letter = 'A'; }
try {
    gettext(Es::Letter);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
gettext(): Argument #1 ($msgid) must be of type string, Es given
