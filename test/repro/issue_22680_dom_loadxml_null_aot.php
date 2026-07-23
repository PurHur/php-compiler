<?php
// AOT: ValueError for null loadXML (#22680). DEP = VM/JIT compliance only.
error_reporting(E_ALL);
$d = new DOMDocument();
try {
    $d->loadXML(null);
    echo "no_throw\n";
} catch (ValueError $e) {
    echo "VE:loadXML\n";
}
