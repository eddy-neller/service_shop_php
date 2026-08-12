<?php

declare(strict_types=1);

namespace App\Presentation\Shared\State;

enum PresentationErrorCode: string
{
    case INVALID_INPUT = 'Invalid input.';
}
