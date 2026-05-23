<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $appName */
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($title); ?> — <?php echo htmlspecialchars($appName); ?></title>
</head>
<body>
<p><?php echo htmlspecialchars($appName); ?></p>
</body>
</html>
