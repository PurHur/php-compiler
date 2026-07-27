<?php
// #23641: the catch block ran but getMessage() was empty and the process aborted
// before reaching the statement after the try.
echo "BEFORE\n";
try {
    throw new LogicException("boom");
} catch (LogicException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
try {
    throw new RuntimeException("second");
} catch (Exception $e) {
    echo "caught2: ", $e->getMessage(), "\n";
}
echo "AFTER\n";
