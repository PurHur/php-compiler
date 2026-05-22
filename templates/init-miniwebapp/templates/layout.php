<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $appName */
$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($title); ?> — <?php echo htmlspecialchars($appName); ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav>
    <a href="<?php echo htmlspecialchars($scriptBase); ?>">Home</a>
    <a href="<?php echo htmlspecialchars($scriptBase); ?>/hello?name=Dev">Hello</a>
    <a href="<?php echo htmlspecialchars($scriptBase); ?>/contact">Contact</a>
</nav>
<main>
<?php
if ('Home' === $title) {
    include __DIR__ . '/home.php';
} elseif ('Hello' === $title) {
    include __DIR__ . '/hello.php';
} elseif ('Contact' === $title) {
    include __DIR__ . '/contact.php';
} elseif ('Thank you' === $title) {
    include __DIR__ . '/thankyou.php';
}
?>
</main>
</body>
</html>
