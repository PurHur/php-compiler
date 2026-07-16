<?php

/** @var string $title */
/** @var string $appName */
echo '<!DOCTYPE html>', "\n";
echo '<html>', "\n";
echo '<head>', "\n";
echo htmlspecialchars($title), ' — ', htmlspecialchars($appName), "\n";
