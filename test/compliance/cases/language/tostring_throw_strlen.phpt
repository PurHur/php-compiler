--TEST--
Language: __toString throw during strlen reaches try/catch (#4284)
--FILE--
<?php
$t = new class {
    public function __toString(): string {
        throw new Exception('boom');
    }
};

try {
    echo strlen($t);
} catch (Throwable $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
--EXPECT--
caught: boom
