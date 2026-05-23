<?php

declare(strict_types=1);

$guestName = $_REQUEST['name'] ?? 'World';
$title = 'Hello';
$appName = 'MiniWebApp';
include __DIR__ . '/layout.php';
