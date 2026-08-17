<?php
// On default 8.2 profile, eval('public const int X') must throw catchable ParseError (Zend), not process Fatal.
try {
    eval('class C { public const int X = 1; }');
    echo "accepted\n";
} catch (ParseError $e) {
    echo 'caught-ParseError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'caught-', get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
