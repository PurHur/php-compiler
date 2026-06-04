--TEST--
stdlib trim/ltrim/rtrim JIT — backed enum case TypeError (#5874)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
foreach (['trim', 'ltrim', 'rtrim'] as $fn) {
    try {
        $fn(Es::B);
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
trim(): Argument #1 ($string) must be of type string, Es given
ltrim(): Argument #1 ($string) must be of type string, Es given
rtrim(): Argument #1 ($string) must be of type string, Es given
