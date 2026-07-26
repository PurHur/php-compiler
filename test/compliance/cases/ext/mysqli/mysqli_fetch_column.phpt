--TEST--
ext/mysqli mysqli_fetch_column / mysqli_result::fetch_column (#22214, ext/mysqli/mysqli_nonapi.c)
--FILE--
<?php
echo function_exists('mysqli_fetch_column') ? 'yes' : 'no', "\n";
echo method_exists('mysqli_result', 'fetch_column') ? 'yes' : 'no', "\n";
$rf = new ReflectionFunction('mysqli_fetch_column');
echo 'proc:', $rf->getNumberOfParameters(), ':', $rf->getNumberOfRequiredParameters();
foreach ($rf->getParameters() as $p) {
    echo ':', $p->getName(), $p->isOptional() ? '?' : '';
}
echo "\n";
$rm = new ReflectionMethod('mysqli_result', 'fetch_column');
echo 'meth:', $rm->getNumberOfParameters(), ':', $rm->getNumberOfRequiredParameters();
foreach ($rm->getParameters() as $p) {
    echo ':', $p->getName(), $p->isOptional() ? '?' : '';
}
echo "\n";
?>
--EXPECT--
yes
yes
proc:2:1:result:column?
meth:1:0:column?
