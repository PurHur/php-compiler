<?php
// AOT: generator try/catch with yield in catch must match Zend (#35008).
function g() {
    try {
        yield 1;
        throw new Exception('e');
    } catch (Exception $e) {
        yield $e->getMessage();
    }
}
foreach (g() as $v) {
    echo $v;
}
