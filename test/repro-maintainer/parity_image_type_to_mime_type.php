<?php
var_dump(function_exists('image_type_to_mime_type'));
if (function_exists('image_type_to_mime_type')) {
    echo image_type_to_mime_type(IMAGETYPE_PNG), "\n";
}
