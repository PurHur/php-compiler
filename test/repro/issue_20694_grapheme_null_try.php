<?php
try {
    grapheme_strlen(null);
    echo "strlen COERCED\n";
} catch (TypeError $e) {
    echo "strlen TypeError\n";
}
try {
    grapheme_substr(null, 0);
    echo "substr COERCED\n";
} catch (TypeError $e) {
    echo "substr TypeError\n";
}
try {
    grapheme_strpos(null, 'a');
    echo "strpos COERCED\n";
} catch (TypeError $e) {
    echo "strpos TypeError\n";
}
