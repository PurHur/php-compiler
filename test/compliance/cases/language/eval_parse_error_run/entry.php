<?php
try {
    eval('syntax error;');
    echo "no-exception\n";
} catch (ParseError $e) {
    echo "ParseError\n";
    echo str_contains($e->getFile(), 'eval') ? "eval-file\n" : "file\n";
}
