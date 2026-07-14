<?php
// #18934 — define(null) TypeError on default profile (ext/standard/basic_functions.c).
try {
    define(null, 1);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
