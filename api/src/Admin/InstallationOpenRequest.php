<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class InstallationOpenRequest
{
    public function __construct(
        #[Assert\Uuid]
        public string $orderId,
        #[Assert\NotBlank]
        public string $from,
        #[Assert\NotBlank]
        public string $to,
        #[Assert\All([new Assert\Length(max: 60)])]
        public array $works = [],
        #[Assert\Length(max: 500)]
        public ?string $note = null,
    ) {}
}
