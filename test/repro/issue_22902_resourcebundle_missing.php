<?php
foreach ([
  ["xx_YY", "ICUDATA-zone"],
  ["zz_ZZ", "ICUDATA"],
  ["en", "no_such_bundle_xyz"],
] as [$loc, $bundle]) {
  $r = @ResourceBundle::create($loc, $bundle, false);
  echo "$loc/$bundle: ", $r === null ? "NULL" : ("OBJ:".get_class($r));
  echo " err=", intl_get_error_code(), " msg=", intl_get_error_message(), "\n";
}
