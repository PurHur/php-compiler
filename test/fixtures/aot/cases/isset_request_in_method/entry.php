<?php

declare(strict_types=1);

class C
{
    public function go(): void
    {
        if (isset($_REQUEST['name'])) {
            echo $_REQUEST['name'];
        } else {
            echo 'MISS';
        }
    }
}

(new C())->go();
