--TEST--
Language: implements Serializable emits E_DEPRECATED (Zend/zend_interfaces.c; #22000)
--FILE--
<?php
error_reporting(E_ALL);

// eval so DECLARE_CLASS runs after error_reporting() (matches Zend lexical order for the notice).
eval(<<<'PHP'
class Legacy implements Serializable {
    public function serialize(): string { return 'X'; }
    public function unserialize($data): void {}
}
PHP);
echo substr(serialize(new Legacy()), 0, 8), "\n";

class Dual implements Serializable {
    public function serialize(): string { return 'Y'; }
    public function unserialize($data): void {}
    public function __serialize(): array { return []; }
    public function __unserialize(array $data): void {}
}
echo "dual\n";

abstract class Abs implements Serializable {
    abstract public function serialize();
    abstract public function unserialize($data);
}
echo "abstract\n";
--EXPECTF--
PHP Deprecated:  Legacy implements the Serializable interface, which is deprecated. Implement __serialize() and __unserialize() instead (or in addition, if support for old PHP versions is necessary) in %s on line %d
C:6:"Leg
dual
abstract
