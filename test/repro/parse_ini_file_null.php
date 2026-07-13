<?php
// Repro #18699: parse_ini_file(null) must ValueError empty filename like Zend, not TypeError.
try {
    parse_ini_file(null);
    fwrite(STDERR, "parse_ini_file(null): expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'parse_ini_file(null): got '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
