<?php

declare(strict_types=1);

/**
 * Issue #8308: assigned `new` on class without __construct must not segfault in AOT.
 */

class EmptyBox
{
}

$f = new EmptyBox();
echo $f instanceof EmptyBox ? "ok\n" : "fail\n";
