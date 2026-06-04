--TEST--
Language: enum instance method throw $this raises Error (#5781, zend_exceptions.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    public function boom(): never {
        throw $this;
    }
}
try {
    E::A->boom();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
Error: Cannot throw objects that do not implement Throwable
