<?php
try {
    substr_count(null, 'a');
    echo "substr_count: uncaught\n";
} catch (TypeError $e) {
    echo 'substr_count: '.$e->getMessage()."\n";
}
try {
    substr_replace(null, 'x', 0);
    echo "substr_replace: uncaught\n";
} catch (TypeError $e) {
    echo 'substr_replace: '.$e->getMessage()."\n";
}
