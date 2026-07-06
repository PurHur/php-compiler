<?php

declare(strict_types=1);

try {
    image_type_to_mime_type(null);
    echo "no exception\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
