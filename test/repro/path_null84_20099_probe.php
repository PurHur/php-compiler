<?php

foreach (['basename', 'dirname', 'pathinfo'] as $fn) {
    try {
        var_export($fn(null));
        echo " $fn\n";
    } catch (Throwable $e) {
        echo get_class($e), " $fn\n";
    }
}
