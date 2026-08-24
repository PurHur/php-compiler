<?php
class ParentS {
    public string $p = 'ok';
}
class ChildS extends ParentS {}
$b = new ChildS();
try {
    unset($b->p);
    $b->p .= 'x';
} catch (Error $e) {
    echo 'Error:', $e->getMessage(), "\n";
}
echo "DONE\n";
