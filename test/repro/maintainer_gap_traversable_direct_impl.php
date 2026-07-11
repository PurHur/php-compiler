<?php

declare(strict_types=1);

class DirectTraversable implements Traversable
{
}

echo "fail: user class implemented Traversable directly\n";
exit(1);
