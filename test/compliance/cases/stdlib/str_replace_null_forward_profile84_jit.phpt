--TEST--
stdlib str_replace()/str_ireplace()/preg_replace() null subject TypeError on 8.4 forward profile JIT (#18914)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['str_replace' => static fn () => str_replace('a', 'b', null),
           'str_ireplace' => static fn () => str_ireplace('a', 'b', null),
           'preg_replace' => static fn () => preg_replace('//', 'x', null)] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
str_replace: str_replace(): Argument #3 ($subject) must be of type array|string, null given
str_ireplace: str_ireplace(): Argument #3 ($subject) must be of type array|string, null given
preg_replace: preg_replace(): Argument #3 ($subject) must be of type array|string, null given
