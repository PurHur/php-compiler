<?php
echo image_type_to_extension(IMAGETYPE_JPEG), "\n";
echo image_type_to_extension(IMAGETYPE_JPEG, false), "\n";
echo image_type_to_extension(IMAGETYPE_BMP), "\n";
echo image_type_to_extension(0) === false ? "unknown\n" : "hit\n";
