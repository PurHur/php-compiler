<?php
ini_set('url_rewriter.tags', 'a=href');
output_add_rewrite_var('sid', 'abc');
ob_start();
echo '<a href="/x">l</a>';
echo ob_get_clean(), PHP_EOL;
