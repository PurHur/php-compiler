--TEST--
Language: ArrayAccess ++ on by-value offsetGet — Notice, no store (#32015, zend_vm_def.h)
--FILE--
<?php
class A implements ArrayAccess
{
    private array $d = [];

    public function offsetExists(mixed $k): bool
    {
        return array_key_exists($k, $this->d);
    }

    public function offsetGet(mixed $k): mixed
    {
        return $this->d[$k] ?? null;
    }

    public function offsetSet(mixed $k, mixed $v): void
    {
        $this->d[$k] = $v;
    }

    public function offsetUnset(mixed $k): void
    {
        unset($this->d[$k]);
    }
}

$a = new A();
$a['k']++;
echo 'isset=', var_export(isset($a['k']), true), "\n";
echo 'read=', var_export($a['k'], true), "\n";
--EXPECTF--
PHP Notice:  Indirect modification of overloaded element of A has no effect in %s on line %d
isset=false
read=NULL
