--TEST--
Language: implements Serializable emits E_DEPRECATED at DECLARE site (Zend/zend_interfaces.c; #22000, #25109)
--FILE--
<?php
error_reporting(E_ALL);
$n = [];
set_error_handler(function ($no, $msg) use (&$n) {
    $n[] = "$no:$msg";
    return true;
});

// Lexical order (not eval): DECLARE after set_error_handler must reach the handler (#25109).
echo 'before=', class_exists('Legacy', false) ? '1' : '0', "\n";
class Legacy implements Serializable {
    public function serialize(): string { return 'X'; }
    public function unserialize($data): void {}
}
echo 'notices=', count($n), "\n";
foreach ($n as $x) {
    echo $x, "\n";
}
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
before=0
notices=1
8192:Legacy implements the Serializable interface, which is deprecated. Implement __serialize() and __unserialize() instead (or in addition, if support for old PHP versions is necessary)
C:6:"Leg
dual
abstract
