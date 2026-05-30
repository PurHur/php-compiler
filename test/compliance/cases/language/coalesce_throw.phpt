--TEST--
null coalesce throw expression (?? throw) — Zend zend_compile.c parity (#3462)
--FILE--
<?php
class Ex {
    public string $m;

    public function __construct(string $m)
    {
        $this->m = $m;
    }
}
try {
    echo ($missing ?? throw new Ex('missing')), "\n";
} catch (Ex $e) {
    echo 'caught:', $e->m, "\n";
}

$ok = 1;
$hit = 0;
try {
    echo ($ok ?? throw new Ex('no')), "\n";
} catch (Ex $e) {
    $hit = 1;
}
echo $hit, "\n";
?>
--EXPECT--
caught:missing
1
0
