--TEST--
stdlib ArrayObject::__construct() direct + subclass parent::__construct (#10169, ext/spl/spl_array.c)
--FILE--
<?php
declare(strict_types=1);

$o = new ArrayObject([1, 2]);
echo count($o), "\n";

$anon = new class extends ArrayObject {
    public function __construct()
    {
        parent::__construct([3, 4, 5]);
    }
};
echo count($anon), "\n";
?>
--EXPECT--
2
3
