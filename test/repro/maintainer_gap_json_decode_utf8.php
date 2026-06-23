<?php
declare(strict_types=1);
try {
    json_decode("\xFF", flags: JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    echo $e->getMessage(), "\n";
    echo $e->getCode(), "\n";
}
