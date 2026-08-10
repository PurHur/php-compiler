--TEST--
Language: get-hook Error getTrace uses $prop::get not __phpc_property_get_* — JIT (#29689)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public string $prop {
        get => $this->prop;
        set => $this->prop = $value;
    }
}
$c = new C;
try {
    echo $c->prop;
} catch (Throwable $e) {
    $t = $e->getTrace()[0];
    echo $t['class'], $t['type'], $t['function'], "\n";
}
--EXPECT--
C->$prop::get
