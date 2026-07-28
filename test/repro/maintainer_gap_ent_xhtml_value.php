<?php
declare(strict_types=1);

// #24067 — ENT_XHTML must be 32 (php-src ENT_HTML_DOC_XHTML), not 17.
echo 'ENT_XHTML=', ENT_XHTML, "\n";
echo 'ENT_XML1=', ENT_XML1, "\n";
echo 'ENT_HTML5=', ENT_HTML5, "\n";
echo 'hs=', htmlspecialchars("<'\">&", ENT_QUOTES | ENT_XHTML), "\n";
echo 'dec=', html_entity_decode('&apos;&amp;', ENT_QUOTES | ENT_XHTML), "\n";
