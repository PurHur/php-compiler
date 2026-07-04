<?php

try {
    implode(new stdClass(), []);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    join(new stdClass(), []);
    echo "uncaught join\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
