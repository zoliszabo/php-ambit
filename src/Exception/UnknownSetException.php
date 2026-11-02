<?php

declare(strict_types=1);

namespace Ambit\Exception;

use InvalidArgumentException;

final class UnknownSetException extends InvalidArgumentException
{
    public function __construct(string $set)
    {
        parent::__construct('Unknown scope set "' . $set . '".');
    }
}
