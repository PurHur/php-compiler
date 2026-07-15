<?php

declare(strict_types=1);

try {
    echo 'try';
} catch (Throwable $e) {
    echo 'catch';
} else {
    echo 'else';
}
