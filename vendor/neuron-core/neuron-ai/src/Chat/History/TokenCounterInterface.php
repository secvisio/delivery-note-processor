<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;

interface TokenCounterInterface
{
    /**
     * @param Message[] $messages
     */
    public function count(array $messages): int;
}
