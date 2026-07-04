<?php

declare(strict_types=1);

#[\Deprecated(message: 'maintainer deprecated probe default profile')]
function maintainer_dep_default(): int
{
    return 7;
}

$last = null;
set_error_handler(static function (int $no, string $msg) use (&$last): bool {
    $last = $msg;

    return true;
});
$result = maintainer_dep_default();
restore_error_handler();

echo 'result=', $result, "\n";
echo 'deprecated=', ($last === null ? 'false' : 'true'), "\n";
