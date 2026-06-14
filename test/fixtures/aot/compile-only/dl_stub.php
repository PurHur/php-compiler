<?php
// AOT compile-only (#3591): dl() JIT lowering links without VM-only throw.
var_dump(function_exists('dl'));
var_dump(@dl('nonexistent.so'));
