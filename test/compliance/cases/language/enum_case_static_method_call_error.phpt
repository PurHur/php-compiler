--TEST--
Language: enum case instance method via :: must Error (#6408, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    public function instance(): string { return 'no'; }
}
try {
    (E::A)::instance();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Non-static method E::instance() cannot be called statically
