<?php

declare(strict_types=1);

DateTime::createFromFormat('Y-m-d', 'bad');
$errors = DateTime::getLastErrors();
if (!\is_array($errors)) {
    echo "expected array from getLastErrors()\n";
    exit(1);
}
if (3 !== $errors['error_count']) {
    echo 'expected error_count=3, got '.var_export($errors['error_count'], true)."\n";
    exit(1);
}

DateTimeImmutable::createFromFormat('Y-m-d', 'bad');
$immutableErrors = DateTimeImmutable::getLastErrors();
if (!\is_array($immutableErrors) || 3 !== $immutableErrors['error_count']) {
    echo 'DateTimeImmutable expected error_count=3, got '
        .var_export($immutableErrors['error_count'] ?? null, true)."\n";
    exit(1);
}

echo "ok\n";
