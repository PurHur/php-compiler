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

$c = new ChildReturn();
$c->m();
echo "ok\n";
