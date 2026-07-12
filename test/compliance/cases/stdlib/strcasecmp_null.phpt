--TEST--
stdlib strcmp family — null operand TypeError under declare(strict_types=1) (#18355, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['strcasecmp', 'strnatcmp', 'strnatcasecmp', 'strcoll'] as $fn) {
    try {
        $fn('a', null);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
strcasecmp: strcasecmp(): Argument #2 ($string2) must be of type string, null given
strnatcmp: strnatcmp(): Argument #2 ($string2) must be of type string, null given
strnatcasecmp: strnatcasecmp(): Argument #2 ($string2) must be of type string, null given
strcoll: strcoll(): Argument #2 ($string2) must be of type string, null given
