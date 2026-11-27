<?php

// Minimal stand-ins so the SOAP client wrappers can be unit tested on hosts
// without ext-soap. When the extension is loaded these are never defined.

if (! class_exists('SoapFault')) {
    class SoapFault extends Exception
    {
        public $faultcode;
        public $faultstring;
        public $detail;

        public function __construct($code, $string, $actor = null, $detail = null, $name = null, $headerFault = null)
        {
            parent::__construct((string) $string);
            $this->faultcode = is_array($code) ? implode(':', $code) : $code;
            $this->faultstring = $string;
            $this->detail = $detail;
        }
    }
}

if (! class_exists('SoapVar')) {
    class SoapVar
    {
        public function __construct(public $data = null, public $encoding = null)
        {
        }
    }
}

if (! defined('XSD_ANYXML')) {
    define('XSD_ANYXML', 147);
}
