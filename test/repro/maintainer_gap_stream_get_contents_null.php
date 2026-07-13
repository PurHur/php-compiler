<?php
// Repro #18712: stream_get_contents(null) must TypeError like Zend, not LogicException.
try {
    stream_get_contents(null);
    fwrite(STDERR, "stream_get_contents(null): expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'stream_get_contents(null): got '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
