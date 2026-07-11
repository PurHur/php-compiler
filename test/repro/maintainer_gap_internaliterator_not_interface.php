<?php

declare(strict_types=1);

class UserInternalIterator implements InternalIterator
{
}

echo "fail: user class implemented InternalIterator\n";
exit(1);
