<?php
try {
    stripcslashes(null);
    echo "stripcslashes: uncaught\n";
} catch (TypeError $e) {
    echo 'stripcslashes: '.$e->getMessage()."\n";
}
