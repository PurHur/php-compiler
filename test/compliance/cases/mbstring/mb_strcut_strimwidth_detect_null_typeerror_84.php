<?php
// Guard — mb_detect_encoding soft-null under PROFILE=8.4 (#21516; strcut/strimwidth soft-null in #21430)
try {
    $r = mb_detect_encoding(null);
    echo 'mb_detect_encoding: OK '.var_export($r, true)."\n";
} catch (TypeError $e) {
    echo 'mb_detect_encoding: '.$e->getMessage()."\n";
}
