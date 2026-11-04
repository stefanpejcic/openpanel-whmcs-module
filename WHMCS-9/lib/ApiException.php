<?php

namespace WHMCS\Module\Server\OpenPanel;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Transport failure or OpenAdmin API error. The code is the HTTP status when
 * OpenAdmin answered, 0 when it could not be reached.
 */
class ApiException extends \Exception
{
}
