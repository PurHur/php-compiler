<?php

declare(strict_types=1);

$fail = 0;

function check_missing(string $function, string $className): void
{
    global $fail;
    $before = error_get_last();
    $result = $function($className);
    if (false !== $result) {
        fwrite(STDERR, "fail: {$function}({$className}) expected false\n");
        $fail = 1;

        return;
    }
    $last = error_get_last();
    if (null === $last || $last === $before) {
        fwrite(STDERR, "fail: {$function}({$className}) no warning\n");
        $fail = 1;

        return;
    }
    if (!str_contains($last['message'], $className) || !str_contains($last['message'], 'does not exist')) {
        fwrite(STDERR, "fail: {$function} warning=".$last['message']."\n");
        $fail = 1;
    }
}

check_missing('class_implements', 'MissingClass');
check_missing('class_parents', 'Missing');
check_missing('class_uses', 'Missing');

exit($fail);
