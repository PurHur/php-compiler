--TEST--
Language: try/catch prints caught exception message (#57, #195, #2084)
--FILE--
<?php
class Ex {
    public string $message;
    public function __construct(string $message) {
        $this->message = $message;
    }
}
$thrown = new Ex('caught-msg');
try {
    throw $thrown;
} catch (Ex $e) {
    echo $thrown->message, "\n";
}
--EXPECT--
caught-msg
