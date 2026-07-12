--TEST--
AOT: chained ?? as inline call argument (#17590)
--FILE--
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
--EXPECT--
ok chained coalesce add
