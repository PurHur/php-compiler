<?php
echo "BEFORE\n";
try {
    throw new LogicException("boom");
} catch (LogicException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
echo "AFTER\n";
