--TEST--
ArrayObject/ArrayIterator ArrayAccess stub arginfo mixed $key + tentative returns (#25840)
--FILE--
<?php
foreach (['ArrayObject', 'ArrayIterator'] as $c) {
    $m = new ReflectionMethod($c, 'offsetExists');
    echo $c, '::offsetExists';
    foreach ($m->getParameters() as $p) {
        echo ' ', $p->getName(), ':', (string) $p->getType();
    }
    echo ' tent=', $m->hasTentativeReturnType() ? (string) $m->getTentativeReturnType() : 'none';
    echo "\n";
}

class ArrayObjectOffsetExistsOverride extends ArrayObject
{
    public function offsetExists($key): bool
    {
        return parent::offsetExists($key);
    }
}
$ov = new ArrayObjectOffsetExistsOverride(['x' => null]);
echo 'override:', isset($ov['x']) ? 'y' : 'n', $ov->offsetExists('x') ? 'y' : 'n', "\n";

$anon = new class extends ArrayObject {
    public function __construct()
    {
        parent::__construct([1, 2]);
    }
};
echo 'anon:', count($anon), "\n";
?>
--EXPECT--
ArrayObject::offsetExists key:mixed tent=bool
ArrayIterator::offsetExists key:mixed tent=bool
override:yy
anon:2
