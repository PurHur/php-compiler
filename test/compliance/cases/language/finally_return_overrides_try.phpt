--TEST--
Language: return in finally overrides try return and suppresses pending exception (#25239)
--FILE--
<?php
function from_try() {
    try { return "try"; } finally { return "finally"; }
}
function from_throw() {
    try { throw new RuntimeException("x"); } finally { return "suppressed"; }
}
function from_catch() {
    try { throw new RuntimeException("x"); }
    catch (RuntimeException $e) { return "catch"; }
    finally { return "finally"; }
}
function from_void_return() {
    try { throw new RuntimeException("x"); } finally { return; }
}
echo from_try(), "\n";
echo from_throw(), "\n";
echo from_catch(), "\n";
var_dump(from_void_return());
--EXPECT--
finally
suppressed
finally
NULL
