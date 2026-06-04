--TEST--
language __sleep/__wakeup declared return types — compile-time fatal (issue #5384)
--FILE--
<?php
class C {
    public function __sleep(): int { return 0; }
}
serialize(new C());
--EXPECTF--
Fatal error: %s::__sleep(): Return type must be array when declared in %s on line %d
