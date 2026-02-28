<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'customer_account')]
class CustomerAccount
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255, unique: true)]
    private string $googleSub;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 160)]
    private string $displayName;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $googleSub, string $email, string $displayName)
    {
        $this->id = Uuid::v7();
        $this->googleSub = $googleSub;
        $this->email = $email;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getGoogleSub(): string { return $this->googleSub; }
    public function getEmail(): string { return $this->email; }
    public function getDisplayName(): string { return $this->displayName; }

    public function updateProfile(string $email, string $displayName): void
    {
        $this->email = $email;
        $this->displayName = $displayName;
    }
}
