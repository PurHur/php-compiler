<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    get_defined_constants(null);
    echo "gdc uncaught\n";
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
try {
    get_defined_functions(null);
    echo "gdf uncaught\n";
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
try {
    get_loaded_extensions(null);
    echo "gle uncaught\n";
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
