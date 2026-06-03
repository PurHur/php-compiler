--TEST--
Language: __toString throw during concat reaches try/catch (#4284)
--FILE--
<?php
$t = new class {
    public function __toString(): string {
        throw new Exception('boom');
    }
};

try {
    echo 'x' . $t;
} catch (Throwable $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
--EXPECT--
caught: boom
