<?php
class Ex extends Exception
{
    public function __construct(string $m)
    {
        parent::__construct($m);
    }
}

try {
    $x = throw new Ex('coalesce') ?? 1;
    echo "fail\n";
} catch (Ex $e) {
    echo "ok\n";
}
