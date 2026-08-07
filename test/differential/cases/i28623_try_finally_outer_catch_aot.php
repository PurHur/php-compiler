<?php
// @differential-repeat: 10   AOT finally epilogue aborted instead of propagating to caller catch (#28623)
function f() {
    try {
        throw new Exception('e');
    } finally {
        echo "F\n";
    }
}
try {
    f();
} catch (Exception $e) {
    echo "C\n";
}
