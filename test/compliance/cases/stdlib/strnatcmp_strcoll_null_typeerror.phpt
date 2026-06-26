--TEST--
stdlib strnatcmp()/strcoll() — null operand TypeError under strict_types (#11956, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
foreach ([['strnatcmp', null, '1', 'string1'], ['strcoll', null, 'a', 'string1']] as [$fn, $a, $b, $which]) {
    try {
        $fn($a, $b);
        echo "uncaught $fn\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
strnatcmp(): Argument #1 ($string1) must be of type string, null given
strcoll(): Argument #1 ($string1) must be of type string, null given
