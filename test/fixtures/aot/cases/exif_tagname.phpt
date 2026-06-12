--TEST--
AOT exif_tagname() (#6105)
--FILE--
<?php
echo exif_tagname(0x0112), "\n";
echo exif_tagname(0x010F), "\n";
echo exif_tagname(0x829A), "\n";
echo exif_tagname(0xFFFF), "\n";
echo exif_tagname(0xFFFE), "\n";
echo exif_tagname(999999) === false ? "unknown\n" : "hit\n";
echo exif_tagname(-1) === false ? "negative\n" : "hit\n";
--EXPECT--
Orientation
Make
ExposureTime
No tag value
Computed value
unknown
negative
