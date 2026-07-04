<?php

declare(strict_types=1);

DateTime::createFromFormat('Y-m-d', '2020-01-01 extra');
$errors = DateTime::getLastErrors();
if (!\is_array($errors)) {
    echo "expected array from getLastErrors()\n";
    exit(1);
}
if (1 !== $errors['error_count']) {
    echo 'expected error_count=1, got '.var_export($errors['error_count'], true)."\n";
    exit(1);
}
if (($errors['errors'][10] ?? null) !== 'Trailing data') {
    echo 'expected errors[10]=Trailing data, got '
        .var_export($errors['errors'][10] ?? null, true)."\n";
    exit(1);
}

DateTimeImmutable::createFromFormat('Y-m-d', '2020-01-01 extra');
$immutableErrors = DateTimeImmutable::getLastErrors();
if (!\is_array($immutableErrors) || 1 !== $immutableErrors['error_count']) {
    echo 'DateTimeImmutable expected error_count=1, got '
        .var_export($immutableErrors['error_count'] ?? null, true)."\n";
    exit(1);
}
if (($immutableErrors['errors'][10] ?? null) !== 'Trailing data') {
    echo 'DateTimeImmutable expected errors[10]=Trailing data, got '
        .var_export($immutableErrors['errors'][10] ?? null, true)."\n";
    exit(1);
}

$ok = DateTime::createFromFormat('Y-m-d', '2020-01-01');
if (!$ok instanceof DateTime) {
    echo "expected successful parse for exact match\n";
    exit(1);
}

echo "ok\n";
