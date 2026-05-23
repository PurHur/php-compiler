<?php

declare(strict_types=1);

function miniwebapp_contact_name_is_valid(): bool
{
    $name = $_REQUEST['name'] ?? '';
    if ($name == '') {
        return false;
    }
    if ($name != substr($name, 0, 200)) {
        return false;
    }

    return true;
}

$route = 'home';
$queryRoute = $_GET['route'] ?? '';
if ('' !== $queryRoute) {
    $route = $queryRoute;
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ('POST' === $method && 'contact' === $route) {
    if (!miniwebapp_contact_name_is_valid()) {
        echo "Invalid contact name\n";

        return;
    }
    $contactName = (string) ($_REQUEST['name'] ?? '');
    echo 'Thank you, ', htmlspecialchars($contactName);
}
