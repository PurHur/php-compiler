<?php
// Guard — mb_detect_encoding null TypeError under PROFILE=8.4 (#20225; strcut/strimwidth soft-null in #21430)
try {
    mb_detect_encoding(null);
    echo "mb_detect_encoding: uncaught\n";
} catch (TypeError $e) {
    echo 'mb_detect_encoding: '.$e->getMessage()."\n";
}
