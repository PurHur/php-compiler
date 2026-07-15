--TEST--
stdlib iconv() — null encoding operands TypeError under declare(strict_types=1) (#18977, ext/iconv/iconv.c)
--FILE--
<?php
declare(strict_types=1);

try {
    iconv(null, 'UTF-8', 'hi');
    echo "from uncaught\n";
} catch (TypeError $e) {
    echo "from te\n";
}

try {
    iconv('UTF-8', null, 'hi');
    echo "to uncaught\n";
} catch (TypeError $e) {
    echo "to te\n";
}
?>
--EXPECT--
from te
to te
