<?php
/**
 * #27899 — number_format() negative $decimals: php-src rounds then MAX(0,dec); no ValueError.
 */
echo number_format(1234.567, -2), "\n";
echo number_format(1234.567, -1), "\n";
echo number_format(1234.567, 0), "\n";
