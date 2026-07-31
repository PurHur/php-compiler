<?php
// #25909 — parent::__construct() / Class::__construct() with no ctor → Cannot call constructor
class ParentNoCtor {}
class Child extends ParentNoCtor {
    public function __construct() {
        parent::__construct();
    }
}
try {
    new Child;
    echo "accepted\n";
} catch (Error $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    ParentNoCtor::__construct();
    echo "named-accepted\n";
} catch (Error $e) {
    echo 'named:', $e->getMessage(), "\n";
}
