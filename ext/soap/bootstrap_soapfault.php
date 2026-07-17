<?php

declare(strict_types=1);

/**
 * Native SoapFault for VM ThrowableManifest (php-src ext/soap/soap.c; #20037).
 */
if (!\class_exists('SoapFault', false)) {
    class SoapFault extends Exception
    {
        public string $faultcode = '';

        public string $faultstring = '';

        public mixed $faultactor = null;

        public mixed $detail = null;

        public function __construct(
            string|null $code = null,
            string $string = '',
            mixed $actor = null,
            mixed $details = null,
            string $name = '',
            mixed $headerFault = null
        ) {
            $this->faultcode = (string) ($code ?? '');
            $this->faultstring = $string;
            $this->faultactor = $actor;
            $this->detail = $details;
            parent::__construct($string !== '' ? $string : $this->faultcode);
        }
    }
}
