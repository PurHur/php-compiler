<?php
function f() {
    try {
        throw new Exception('inner');
    } catch (Exception $e) {
        throw new Exception('outer');
    }
}
f();
