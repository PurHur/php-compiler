<?php
// #28229 — round() excess argc → ArgumentCountError (Zend math.stub.php), not LogicException.
try {
    round(1.5, 0, 1, true);
    echo "ran\n";
} catch (ArgumentCountError $e) {
    echo 'ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo round(1.5), "\n";
