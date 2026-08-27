<?php
// AOT: ZipArchive::isCompressionMethodSupported / isEncryptionMethodSupported (#35498 leftover of #35478).
echo 'store=';
var_export(ZipArchive::isCompressionMethodSupported(ZipArchive::CM_STORE));
echo "\n";
echo 'default=';
var_export(ZipArchive::isCompressionMethodSupported(ZipArchive::CM_DEFAULT));
echo "\n";
echo 'deflate=';
var_export(ZipArchive::isCompressionMethodSupported(8));
echo "\n";
echo 'enc_none=';
var_export(ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_NONE));
echo "\n";
echo 'enc_aes=';
var_export(ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256));
echo "\n";
echo 'enc_bad=';
var_export(ZipArchive::isEncryptionMethodSupported(99));
echo "\n";
echo 'store_enc=';
var_export(ZipArchive::isCompressionMethodSupported(ZipArchive::CM_STORE, false));
echo "\n";
