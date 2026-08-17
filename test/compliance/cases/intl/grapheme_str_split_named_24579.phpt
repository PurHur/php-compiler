--TEST--
grapheme_str_split Reflection + Zend named string/length (VM, issue #24579)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$rf = new ReflectionFunction('grapheme_str_split');
echo 'arity=', $rf->getNumberOfParameters(), PHP_EOL;
echo 'req=', $rf->getNumberOfRequiredParameters(), PHP_EOL;
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none', PHP_EOL;
foreach ($rf->getParameters() as $p) {
    $t = $p->getType();
    echo 'p=', $p->getName();
    echo ' type=', $t ? (string) $t : '(none)';
    echo ' opt=', $p->isOptional() ? '1' : '0';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', json_encode($p->getDefaultValue());
    }
    echo PHP_EOL;
}
try {
    var_export(grapheme_str_split(string: 'abcdef', length: 2));
    echo PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
try {
    grapheme_str_split(str: 'abcdef');
    echo "legacy_str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
var_export(grapheme_str_split('abcdef', 2));
echo PHP_EOL;
?>
--EXPECT--
arity=2
req=1
ret=array|false
p=string type=string opt=0
p=length type=int opt=1 def=1
array (
  0 => 'ab',
  1 => 'cd',
  2 => 'ef',
)
Unknown named parameter $str
array (
  0 => 'ab',
  1 => 'cd',
  2 => 'ef',
)
