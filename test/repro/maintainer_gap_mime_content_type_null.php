<?php
// Repro #18711: mime_content_type(null) must TypeError like Zend, not Warning+false.
try {
    mime_content_type(null);
    fwrite(STDERR, "mime_content_type(null): expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'mime_content_type(null): got '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
