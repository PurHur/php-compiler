<?php
// @differential-skip-aot: AOT uncaught stack is #0 {main} only (#36383)
function inner() {
    throw new Exception('nested');
}
function outer() {
    inner();
}
outer();
