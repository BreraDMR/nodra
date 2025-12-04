<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'admin_user')]
class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $password;

    #[ORM\Column(length: 120)]
    private string $name;

    public function __construct(string $email, string $password, string $name)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->password = $password;
        $this->name = $name;
    }

    public function getId(): Uuid { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getName(): string { return $this->name; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): ?string { return $this->password; }
    public function getRoles(): array { return ['ROLE_ADMIN']; }
    public function eraseCredentials(): void {}
}
