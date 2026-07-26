<?php
// Compile-only (#7204): filter_input() must lower enum-case TypeError on var_name.
enum K: string { case X = 'x'; }
try {
    filter_input(INPUT_GET, K::X, FILTER_DEFAULT);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
