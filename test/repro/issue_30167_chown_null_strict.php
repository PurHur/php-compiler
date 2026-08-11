<?php

declare(strict_types=1);

try {
    chown(null, 0);
    echo "chown: fail\n";
} catch (TypeError $e) {
    echo 'chown: ', $e->getMessage(), "\n";
}
try {
    chgrp(null, 0);
    echo "chgrp: fail\n";
} catch (TypeError $e) {
    echo 'chgrp: ', $e->getMessage(), "\n";
}
try {
    lchown(null, 0);
    echo "lchown: fail\n";
} catch (TypeError $e) {
    echo 'lchown: ', $e->getMessage(), "\n";
}
try {
    lchgrp(null, 0);
    echo "lchgrp: fail\n";
} catch (TypeError $e) {
    echo 'lchgrp: ', $e->getMessage(), "\n";
}
