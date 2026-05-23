<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $appName */
$scriptBase = $_SERVER['SCRIPT_NAME'];
?>
<title><?php echo htmlspecialchars($title); ?> — <?php echo htmlspecialchars($appName); ?></title>
<?php
if ('Home' === $title) {
    include __DIR__ . '/home.php';
}
