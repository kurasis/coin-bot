<?php

namespace App\Blockchain;

use App\Blockchain\Exceptions\RangeLimitException;

/** Reduce topic arrays first when the gateway rejects the request payload. */
final class QueryPayloadLimitException extends RangeLimitException {}
