--TEST--
SPL empty subclass extend — SplQueue/SplStack compile and run (ext/spl/spl_dllist.c; #14757)
--FILE--
<?php
declare(strict_types=1);

class ExtendSplQueue extends SplQueue
{
}

class ExtendSplStack extends SplStack
{
}

$q = new ExtendSplQueue();
$q->push(1);
$q->push(2);
echo 'queue='.$q->count()."\n";

$s = new ExtendSplStack();
$s->push(10);
echo 'stack='.$s->count()."\n";
echo "ok\n";
?>
--EXPECT--
queue=2
stack=1
ok
