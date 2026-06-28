--TEST--
stdlib SplObserver / SplSubject — interface method introspection (#13133, ext/spl/spl_observer.c)
--FILE--
<?php
var_export(method_exists('SplObserver', 'update'));
echo "\n";
var_export(method_exists('SplSubject', 'attach'));
echo "\n";
var_export(method_exists('SplSubject', 'detach'));
echo "\n";
var_export(method_exists('SplSubject', 'notify'));
echo "\n";
$names = array_map(
    static fn (ReflectionMethod $m): string => $m->getName(),
    (new ReflectionClass('SplObserver'))->getMethods()
);
var_export($names);
echo "\n";
?>
--EXPECT--
true
true
true
true
array (
  0 => 'update',
)
