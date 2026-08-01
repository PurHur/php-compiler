--TEST--
stdlib filter_var_array add_empty bool + filter_input Reflection defaults (#26184)
--FILE--
<?php
declare(strict_types=1);
foreach (['filter_input', 'filter_var_array'] as $f) {
    $r = new ReflectionFunction($f);
    echo "$f:";
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
}
try {
    var_export(filter_var_array(['a' => 1], FILTER_DEFAULT, true));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(filter_var_array(array: ['a' => 1], options: FILTER_DEFAULT, add_empty: true));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
filter_input: $type:int $var_name:string $filter:int=516 $options:array|int=0 ret:mixed
filter_var_array: $array:array $options:array|int=516 $add_empty:bool=true ret:array|false|null
array (
  'a' => '1',
)
array (
  'a' => '1',
)
