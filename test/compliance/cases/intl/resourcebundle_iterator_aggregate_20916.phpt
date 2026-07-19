--TEST--
ResourceBundle implements IteratorAggregate; foreach matches count (#20916)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip ResourceBundle withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$rb = ResourceBundle::create('en', 'ICUDATA-zone');
if (false === $rb || null === $rb) {
    $rb = ResourceBundle::create('en', null);
}
$ifaces = array_values(class_implements($rb) ?: []);
sort($ifaces);
echo 'implements=', implode(',', $ifaces), "\n";
echo 'traversable=', (int) ($rb instanceof Traversable), "\n";
echo 'iteratoraggregate=', (int) ($rb instanceof IteratorAggregate), "\n";
$c = count($rb);
echo 'count=', $c, "\n";
$n = 0;
$firstKey = null;
$firstType = null;
foreach ($rb as $k => $v) {
    if (0 === $n) {
        $firstKey = $k;
        $firstType = get_debug_type($v);
    }
    ++$n;
}
echo 'foreach_n=', $n, "\n";
echo 'match=', (int) ($n === $c), "\n";
echo 'first_key=', (string) $firstKey, "\n";
echo 'first_type=', (string) $firstType, "\n";
?>
--EXPECTF--
implements=Countable,IteratorAggregate,Traversable
traversable=1
iteratoraggregate=1
count=%d
foreach_n=%d
match=1
first_key=%s
first_type=%s
