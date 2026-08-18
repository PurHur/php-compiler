<?php
error_reporting(E_ALL);

class C
{
    public function go(): void
    {
        echo $missing, "\n";
    }
}

(new C)->go();
