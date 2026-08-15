<?php
// #31184 — 1-arg pg_escape_* FETCH_DEFAULT_LINK E_DEPRECATED + bytea bytes + literal/identifier arity.
error_reporting(E_ALL);
ini_set('display_errors', '1');

$input = "a'b";

set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
        echo 'DEP: ', $errstr, "\n";

        return true;
    }

    return false;
});

$s = pg_escape_string($input);
echo 'escape_string=', bin2hex($s), ' text=', $s, "\n";

$b = pg_escape_bytea($input);
echo 'escape_bytea=', bin2hex($b), ' text=', $b, "\n";

try {
    pg_escape_literal($input);
    echo "literal=ok\n";
} catch (Throwable $e) {
    echo 'literal=', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    pg_escape_identifier($input);
    echo "identifier=ok\n";
} catch (Throwable $e) {
    echo 'identifier=', get_class($e), ': ', $e->getMessage(), "\n";
}
