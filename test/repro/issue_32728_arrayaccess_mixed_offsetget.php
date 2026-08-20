<?php
/**
 * #32728 — AOT ArrayAccess offsetGet(): mixed must return the payload, not the receiver.
 * php-src: Zend/zend_interfaces.c / Zend/zend_object_handlers.c (read_dimension → offsetGet)
 */
class C implements ArrayAccess
{
    private $d = [];

    public function offsetExists($o): bool
    {
        return isset($this->d[$o]);
    }

    public function offsetGet($o): mixed
    {
        return $this->d[$o];
    }

    public function offsetSet($o, $v): void
    {
        $this->d[$o] = $v;
    }

    public function offsetUnset($o): void
    {
        unset($this->d[$o]);
    }
}

$c = new C();
$c['a'] = 1;
echo $c['a'], "\n";

function f(): mixed
{
    return 7;
}
echo f(), "\n";
