--TEST--
stdlib gc_status() PHP 8.4 fields running/protected/full/buffer_size (#9840)
--FILE--
<?php
$s = gc_status();
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    echo $key, '_', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
    if (array_key_exists($key, $s)) {
        echo $key, '_type=', \is_bool($s[$key]) ? 'bool' : (\is_int($s[$key]) ? 'int' : 'other'), "\n";
    }
}
--EXPECT--
running_yes
running_type=bool
protected_yes
protected_type=bool
full_yes
full_type=bool
buffer_size_yes
buffer_size_type=int
