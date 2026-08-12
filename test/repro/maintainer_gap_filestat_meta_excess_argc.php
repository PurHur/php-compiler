<?php

/**
 * #30545 — filesize/filetype/filemtime/filectime/fileatime excess argc → ArgumentCountError.
 */
error_reporting(E_ALL);

foreach (['filesize', 'filetype', 'filemtime', 'filectime', 'fileatime'] as $fn) {
    try {
        $fn('/tmp', 1);
        echo "$fn: NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}

try {
    filesize();
    echo "filesize missing: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
