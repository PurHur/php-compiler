--TEST--
collator_compare()/collator_create() Reflection object/string1/string2 + named args (#25497)
--SKIPIF--
<?php
if (!extension_loaded('intl') || !function_exists('collator_compare') || !function_exists('collator_create')) {
    die('skip host php-intl / collator required');
}
?>
--FILE--
<?php
declare(strict_types=1);

foreach (['collator_compare', 'collator_create'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, " arity=", $rf->getNumberOfParameters(), " req=", $rf->getNumberOfRequiredParameters(), "\n";
    echo $fn, " ret=", $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
    foreach ($rf->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', ($t ? (string) $t.' ' : ''), '$', $p->getName();
        if ($p->isOptional()) {
            echo ' OPT';
            if ($p->isDefaultValueAvailable()) {
                echo '=', json_encode($p->getDefaultValue());
            }
        } else {
            echo ' REQ';
        }
        echo "\n";
    }
}

$c = Collator::create('en_US');
echo 'positional=', var_export(collator_compare($c, 'a', 'b'), true), "\n";
try {
    echo 'named=', var_export(collator_compare(object: $c, string1: 'a', string2: 'b'), true), "\n";
} catch (Throwable $e) {
    echo 'named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    collator_compare(coll: $c, str1: 'a', str2: 'b');
    echo "legacy_names accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
collator_compare arity=3 req=3
collator_compare ret=int|false
  Collator $object REQ
  string $string1 REQ
  string $string2 REQ
collator_create arity=1 req=1
collator_create ret=?Collator
  string $locale REQ
positional=-1
named=-1
Unknown named parameter $coll
