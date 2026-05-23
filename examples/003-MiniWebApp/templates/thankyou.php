<?php

/** @var string $name Validated contact name (set by Router before include). */
?>
<h1>Thank you, <?php echo htmlspecialchars($_REQUEST['name'] ?? ''); ?></h1>
