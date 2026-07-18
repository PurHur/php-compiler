<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $appName */
echo '<title>', htmlspecialchars($title), ' — ', htmlspecialchars($appName), "</title>\n";
$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
echo '<a href="', htmlspecialchars($scriptBase), "\">Home</a>\n";
echo '<a href="', htmlspecialchars($scriptBase), "/hello\">Hello</a>\n";
echo '<a href="', htmlspecialchars($scriptBase), "/contact\">Contact</a>\n";
