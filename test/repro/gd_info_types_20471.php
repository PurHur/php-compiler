<?php

foreach (['gd_info', 'imagetypes'] as $n) {
    echo $n, '=', function_exists($n) ? '1' : '0', PHP_EOL;
}
$mask = imagetypes();
echo 'mask=', $mask, PHP_EOL;
echo 'png=', (int) (0 !== ($mask & IMG_PNG)), PHP_EOL;
echo 'jpeg=', (int) (0 !== ($mask & IMG_JPEG)), PHP_EOL;
echo 'gif=', (int) (0 !== ($mask & IMG_GIF)), PHP_EOL;
echo 'webp=', (int) (0 !== ($mask & IMG_WEBP)), PHP_EOL;
echo 'bmp=', (int) (0 !== ($mask & IMG_BMP)), PHP_EOL;
echo 'avif=', (int) (0 !== ($mask & IMG_AVIF)), PHP_EOL;
echo 'wbmp=', (int) (0 !== ($mask & IMG_WBMP)), PHP_EOL;
$info = gd_info();
echo 'version=', isset($info['GD Version']) ? '1' : '0', PHP_EOL;
echo 'png_support=', (int) (!empty($info['PNG Support'])), PHP_EOL;
echo 'wbmp_support=', (int) (!empty($info['WBMP Support'])), PHP_EOL;
echo 'ok', PHP_EOL;
