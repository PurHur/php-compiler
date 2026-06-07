--TEST--
Language: readonly property set hook rejects post-construct backing write (issue #4518, zend_compile.c)
--FILE--
<?php
class C {
    public readonly string $name {
        set (string $value) {
            $this->name = strtoupper($value);
        }
    }
    public function __construct(string $v) {
        $this->name = $v;
    }
}

$c = new C('hi');
try {
    $c->name = 'no';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot modify readonly property C::$name
