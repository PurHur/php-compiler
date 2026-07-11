<?php
echo file_exists(filename: __FILE__) ? "true\n" : "false\n";
echo is_readable(filename: __FILE__) ? "true\n" : "false\n";
echo filesize(filename: __FILE__) > 0 ? "true\n" : "false\n";
