<?php

declare(strict_types=1);

echo "before\n";

class UserDateTime implements DateTimeInterface
{
}

echo "fail: user class implemented DateTimeInterface\n";
exit(1);
