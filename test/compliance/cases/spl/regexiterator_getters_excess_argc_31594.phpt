--TEST--
RegexIterator getPregFlags/getMode/getFlags excess argc → ArgumentCountError (#31594)
--FILE--
<?php
$it = new RegexIterator(new ArrayIterator(['a']), '/a/');
echo 'zero getPregFlags=', var_export($it->getPregFlags(), true), "\n";
echo 'zero getMode=', var_export($it->getMode(), true), "\n";
echo 'zero getFlags=', var_export($it->getFlags(), true), "\n";
foreach (['getPregFlags', 'getMode', 'getFlags'] as $m) {
    try {
        $v = $it->$m(1);
        echo $m, ' ret=';
        var_export($v);
        echo "\n";
    } catch (Throwable $e) {
        echo $m, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
zero getPregFlags=0
zero getMode=0
zero getFlags=0
getPregFlags ArgumentCountError:RegexIterator::getPregFlags() expects exactly 0 arguments, 1 given
getMode ArgumentCountError:RegexIterator::getMode() expects exactly 0 arguments, 1 given
getFlags ArgumentCountError:RegexIterator::getFlags() expects exactly 0 arguments, 1 given
