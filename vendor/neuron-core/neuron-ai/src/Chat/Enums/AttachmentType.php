<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Enums;

enum AttachmentType: string
{
    case DOCUMENT = 'document';
    case IMAGE = 'image';
}
