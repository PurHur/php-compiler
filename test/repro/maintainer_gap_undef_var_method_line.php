<?php

error_reporting(E_ALL);
class C
{
    public function go(): void
    {
        echo $missing, "\n"; // Zend: this line; VM currently cites go() below
    }
}
(new C)->go();
