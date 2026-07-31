<?php
class C extends ArrayObject {
    public function offsetExists($key): bool {
        return parent::offsetExists($key);
    }
}
echo "override_ok\n";

$anon = new class extends ArrayObject {
    public function __construct() {
        parent::__construct([1, 2]);
    }
};
echo "anon_ok:", count($anon), "\n";
