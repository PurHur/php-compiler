--TEST--
Language: enum case object graph — strcmp TypeError and BackedEnum::from() (#7134, zend_enum.c)
--FILE--
<?php
enum E: string { case A = 'x'; case B = 'y'; }
try {
    strcmp(E::A, 'x');
    echo "strcmp uncaught\n";
} catch (TypeError $e) {
    echo "strcmp TypeError\n";
} catch (Throwable $e) {
    echo 'strcmp ', get_class($e), "\n";
}
try {
    var_export(E::from('x'));
    echo "\n";
} catch (Throwable $e) {
    echo 'from ', get_class($e), "\n";
}
?>
--EXPECT--
strcmp TypeError
\E::A
