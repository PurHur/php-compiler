--TEST--
stdlib filter_input_array add_empty bool + Reflection options (#26201)
--GET--
a=1
--FILE--
<?php
declare(strict_types=1);
$r = new ReflectionFunction('filter_input_array');
echo 'filter_input_array:';
foreach ($r->getParameters() as $p) {
    echo ' $'.$p->getName();
    if ($p->hasType()) {
        echo ':'.(string) $p->getType();
    }
    if ($p->isDefaultValueAvailable()) {
        echo '='.var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        echo '=?';
    }
}
echo $r->hasReturnType() ? (' ret:'.(string) $r->getReturnType()) : ' ret:none';
echo "\n";
try {
    var_export(filter_input_array(INPUT_GET, FILTER_DEFAULT, true));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(filter_input_array(type: INPUT_GET, options: FILTER_DEFAULT, add_empty: true));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
filter_input_array: $type:int $options:array|int=516 $add_empty:bool=true ret:array|false|null
array (
  'a' => '1',
)
array (
  'a' => '1',
)
