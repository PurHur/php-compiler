<?php
/**
 * #28314 — image_type_to_extension AOT runtime still returns string|false
 * (image.c). Reflection metadata is exercised on VM; this guards the
 * false-on-unknown and string-on-known paths under native AOT.
 * Uses literal IMAGETYPE ids (2=JPEG) so NestedJIT does not depend on
 * constant folding of IMAGETYPE_* under thin AOT.
 */
echo 'value0=', var_export(image_type_to_extension(0), true), "\n";
echo 'value2=', var_export(image_type_to_extension(2), true), "\n";
echo 'value2_nodot=', var_export(image_type_to_extension(2, false), true), "\n";
