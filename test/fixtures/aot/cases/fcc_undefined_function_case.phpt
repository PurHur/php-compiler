--TEST--
AOT: first-class callable undefined-function Error preserves case (#27106, #26690)
--FILE--
<?php
try {
    $f = FooBar(...);
    echo "fcc uncaught\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Call to undefined function FooBar()
