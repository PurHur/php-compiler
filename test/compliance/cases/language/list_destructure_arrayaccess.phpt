--TEST--
Language: list destructuring from ArrayAccess reads via offsetGet (#7440, zend_execute.c)
--FILE--
<?php
class ListDestructArrayAccess implements ArrayAccess
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}

$obj = new ListDestructArrayAccess(['alpha', 'beta']);
[$a, $b] = $obj;
echo $a, ',', $b, "\n";
list($x, $y) = $obj;
echo $x, ',', $y, "\n";
--EXPECT--
alpha,beta
alpha,beta
