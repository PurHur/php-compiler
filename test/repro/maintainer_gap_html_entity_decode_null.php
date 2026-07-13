<?php
// #18590 — html_entity_decode(null) must TypeError (ext/standard/html.c)
try {
    html_entity_decode(null);
    echo "html_entity_decode: no_ex\n";
} catch (TypeError $e) {
    echo "html_entity_decode: TypeError\n";
}
try {
    htmlspecialchars_decode(null);
    echo "htmlspecialchars_decode: no_ex\n";
} catch (TypeError $e) {
    echo "htmlspecialchars_decode: TypeError\n";
}
