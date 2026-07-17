--TEST--
SPL SplFileInfo::_bad_state_ex invalid-state Error (#20109, ext/spl/spl_directory.c)
--FILE--
<?php
$i = new SplFileInfo('/');
echo (int) method_exists($i, '_bad_state_ex'), "\n";
try {
    $i->_bad_state_ex();
    echo "ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
The parent constructor was not called: the object is in an invalid state
