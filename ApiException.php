<?php

declare(strict_types=1);

namespace Tglux\Smm;

/**
 * Thrown when the panel answers with an error, or the request never got that far.
 *
 * The SMM API v2 convention is unusual and worth knowing: every response is HTTP 200, and a
 * failure is a normal JSON body of the shape {"error": "Incorrect order ID"}. Checking the
 * status code therefore tells you nothing, which is exactly why this library exists.
 */
class ApiException extends \RuntimeException
{
}
