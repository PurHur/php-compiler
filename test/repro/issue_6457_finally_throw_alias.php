<?php
// Reuse one variable for try and finally throws (operand aliasing).
$e = new Exception('inner');
try {
    throw $e;
} finally {
    $e = new Exception('finally');
    throw $e;
}
