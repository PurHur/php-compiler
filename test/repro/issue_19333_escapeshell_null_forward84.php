<?php
foreach (['escapeshellarg', 'escapeshellcmd'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: coerce\n";
    } catch (TypeError $e) {
        echo "{$fn}: TypeError\n";
    } catch (Throwable $e) {
        echo "{$fn}: ", get_class($e), ':', $e->getMessage(), "\n";
    }
}
