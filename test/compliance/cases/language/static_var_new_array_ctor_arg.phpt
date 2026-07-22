--TEST--
Function-local static `new Class([...])` materializes array ctor args (issue #22390)
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

function staticArrayObject(): void
{
    static $s = new ArrayObject([1, 2]);
    echo 'ao count=', $s->count(), ' first=', $s[0], "\n";
}

function staticBox(): void
{
    static $s = new Box([3, 4]);
    echo 'box count=', count($s->a), ' first=', $s->a[0], "\n";
}

function staticDateTime(): void
{
    static $s = new DateTimeImmutable('2020-01-01');
    echo $s->format('Y'), "\n";
}

function staticStdClass(): void
{
    static $t = new stdClass();
    echo get_class($t), "\n";
}

staticArrayObject();
staticArrayObject();
staticBox();
staticBox();
staticDateTime();
staticStdClass();
--EXPECT--
ao count=2 first=1
ao count=2 first=1
box count=2 first=3
box count=2 first=3
2020
stdClass
