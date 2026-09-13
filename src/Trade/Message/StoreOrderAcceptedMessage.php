<?php

declare(strict_types=1);

namespace App\Trade\Message;

final readonly class StoreOrderAcceptedMessage
{
    /** @param array<string, mixed> $envelope */
    public function __construct(public array $envelope)
    {
    }
}
