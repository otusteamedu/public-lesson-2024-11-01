<?php

namespace App\DTO;

class TimeResult
{
    public function __construct(
        public readonly int $actualTime,
        public readonly int $estimatedTime,
    ) {
    }
}
