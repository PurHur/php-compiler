<?php
echo sprintf('%+d', 5), "\n";
try {
    echo sprintf('%#x', 255), "\n";
} catch (ValueError $e) {
    echo "ValueError\n";
    echo $e->getMessage(), "\n";
}
echo sprintf('% d', 5), "\n";
echo sprintf('%+d', -5), "\n";
