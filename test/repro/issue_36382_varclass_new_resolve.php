<?php
/**
 * #36382 — variable-class `new $name` must resolve the named class (Zend), not a
 * falsely-matched helper (was InstanceOfJitHelper via broken ABI_STRCASECMP select).
 * php-src: Zend/zend_execute.c ZEND_FETCH_CLASS / object_init_ex.
 */
final class TargetAlpha
{
    public string $mark = 'alpha';
}

final class TargetBeta
{
    public string $mark = 'beta';
}

$name = TargetBeta::class;
$obj = new $name;
echo get_class($obj), "\n";
echo $obj->mark, "\n";

$opts = ['c' => TargetAlpha::class];
$obj2 = new $opts['c'];
echo get_class($obj2), "\n";
echo $obj2->mark, "\n";
echo "OK\n";
