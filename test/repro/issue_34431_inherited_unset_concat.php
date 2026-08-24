<?php
class ParentS { public string $p = 'hi'; }
class ChildS extends ParentS {}
$b = new ChildS();
try {
    unset($b->p);
    $b->p .= 'x';
    echo "survived:", $b->p, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo "DONE\n";
