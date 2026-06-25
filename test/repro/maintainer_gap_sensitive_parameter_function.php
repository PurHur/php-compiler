<?php
// Issue #11638 — #[\SensitiveParameter] on function must fail at compile time.
#[\SensitiveParameter]
function probe($x): void
{
    echo "ok\n";
}

probe('secret');
