<?php

namespace PHPMailer\PHPMailer;

class Exception extends \Exception
{
    /** Prettify error message output. */
    public function errorMessage()
    {
        return '<strong>' . htmlspecialchars($this->getMessage(), ENT_COMPAT | ENT_HTML401) . "</strong><br />\n";
    }
}
