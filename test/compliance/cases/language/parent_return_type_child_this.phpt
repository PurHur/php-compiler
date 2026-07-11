--TEST--
Language: parent return type accepts $this from child call (issue #13533, Zend/zend_execute.c)
--FILE--
<?php
class Base
{
}
class ParentReturn extends Base
{
    public function m(): parent
    {
        return $this;
    }
}
class ChildReturn extends ParentReturn
{
}
(new ChildReturn())->m();
echo "ok\n";
--EXPECT--
ok
