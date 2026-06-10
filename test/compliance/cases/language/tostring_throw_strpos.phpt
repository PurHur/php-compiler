--TEST--
Language: __toString throw during strpos reaches try/catch (#4822)
--FILE--
<?php
$t = new class {
    public function __toString(): string {
        throw new Exception('boom');
    }
};

try {
    echo strpos('haystack', $t);
} catch (Throwable $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
--EXPECT--
caught: boom
