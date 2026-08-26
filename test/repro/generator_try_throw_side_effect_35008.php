<?php
// AOT: try-body after yield (echo+throw) must run before catch yield (#35008).
function g() {
    try {
        yield 1;
        echo 'T';
        throw new Exception('e');
    } catch (Exception $e) {
        yield $e->getMessage();
    }
}
foreach (g() as $v) {
    echo $v;
}
