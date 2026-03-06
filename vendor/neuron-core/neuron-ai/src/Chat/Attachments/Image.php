<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Attachments;

use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Enums\AttachmentType;

class Image extends Attachment
{
    public function __construct(
        string $image,
        AttachmentContentType $type = AttachmentContentType::URL,
        ?string $mediaType = null
    ) {
        parent::__construct(
            AttachmentType::IMAGE,
            $image,
            $type,
            $mediaType
        );
    }
}
