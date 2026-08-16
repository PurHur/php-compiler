--TEST--
AOT: strtr() three-arg null $from under strict_types cites array|string (#31409)
--FILE--
<?php
declare(strict_types=1);
try {
    strtr('a', null, 'b');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
strtr(): Argument #2 ($from) must be of type array|string, null given
