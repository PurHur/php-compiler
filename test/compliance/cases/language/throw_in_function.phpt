--TEST--
Language: throw unwinds through caller catch (#57, #2084)
--FILE--
<?php
class E {
    public string $message;
    public function __construct(string $message) {
        $this->message = $message;
    }
}

function inner(E $out): void {
    $out->message = 'from-inner';
    throw $out;
}

$thrown = new E('');
try {
    inner($thrown);
} catch (E $e) {
    echo $thrown->message, "\n";
}
--EXPECT--
from-inner
