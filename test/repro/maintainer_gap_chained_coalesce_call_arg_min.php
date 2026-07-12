<?php
function ok(string $label, $got, $expected): void
{
    if ($got === $expected) {
        echo "ok {$label}\n";
        return;
    }
    echo "fail {$label}\n";
}

ok('chained coalesce add', null ?? null ?? 1 + 2, 3);
