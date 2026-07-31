--TEST--
Language: #[\ReturnTypeWillChange] on method — accepted (#25722)
--FILE--
<?php
class A
{
    #[\ReturnTypeWillChange]
    public function m()
    {
        return 1;
    }
}
echo (new A())->m(), "\n";
--EXPECT--
1
