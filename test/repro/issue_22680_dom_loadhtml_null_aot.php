<?php
// AOT: ValueError for null loadHTML (#22680). DEP = VM/JIT compliance only.
error_reporting(E_ALL);
$d = new DOMDocument();
try {
    $d->loadHTML(null);
    echo "no_throw\n";
} catch (ValueError $e) {
    echo "VE:loadHTML\n";
}
