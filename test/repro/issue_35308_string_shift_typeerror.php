<?php
// Repro #35308 — AOT non-numeric string << / >> must TypeError like Zend (not SIGABRT).
try {
    var_dump('a' << 1);
} catch (TypeError $e) {
    echo 'string <<: TypeError:', $e->getMessage(), "\n";
}
try {
    var_dump('a' >> 1);
} catch (TypeError $e) {
    echo 'string >>: TypeError:', $e->getMessage(), "\n";
}
$s = 'a';
try {
    var_dump($s << 1);
} catch (TypeError $e) {
    echo 'var <<: TypeError:', $e->getMessage(), "\n";
}
echo 'numeric: ', '2' << 1, "\n";
