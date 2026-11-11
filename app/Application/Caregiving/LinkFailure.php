<?php

namespace App\Application\Caregiving;

use RuntimeException;

final class LinkFailure extends RuntimeException
{
    public function __construct(public readonly string $machineCode, public readonly int $status)
    {
        parent::__construct($machineCode);
    }
}
