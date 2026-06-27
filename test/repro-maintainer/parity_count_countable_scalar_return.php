<?php

declare(strict_types=1);

class CountableStringReturn implements Countable
{
    public function count()
    {
        return '3';
    }
}

echo count(new CountableStringReturn()), "\n";
