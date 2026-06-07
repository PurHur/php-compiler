--TEST--
Language: private/protected __clone() — Zend visibility Error message (#7352)
--FILE--
<?php
class PrivateClone {
    private function __clone() {}
}
try {
    clone new PrivateClone();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

class ProtectedBase {
    protected function __clone() {}
}
class ProtectedChild extends ProtectedBase {
}
try {
    clone new ProtectedChild();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Call to private PrivateClone::__clone() from global scope
Call to protected ProtectedBase::__clone() from global scope
