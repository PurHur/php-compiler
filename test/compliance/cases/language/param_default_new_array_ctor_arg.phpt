--TEST--
Parameter default `new Class([...])` materializes array ctor args (issue #22390)
--FILE--
<?php
declare(strict_types=1);

class Box
{
    /** @var list<int> */
    public array $a;

    public function __construct(array $a)
    {
        $this->a = $a;
    }
}

function withBox(Box $s = new Box([1, 2])): void
{
    echo 'param box count=', count($s->a), ' first=', $s->a[0], "\n";
}

function withArrayObject(ArrayObject $s = new ArrayObject([9])): void
{
    echo 'param ao count=', $s->count(), ' first=', $s[0], "\n";
}

withBox();
withArrayObject();
--EXPECT--
param box count=2 first=1
param ao count=1 first=9
