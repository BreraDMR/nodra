<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Catalog\CatalogSeeder;
use App\Entity\AdminUser;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher, private CatalogSeeder $seeder) {}

    public function load(ObjectManager $manager): void
    {
        $admin = new AdminUser('admin@nodra.test', '', 'NODRA Studio');
        $manager->persist(new AdminUser(
            'admin@nodra.test',
            $this->passwordHasher->hashPassword($admin, 'NodraDemo2026!'),
            'NODRA Studio',
        ));
        $manager->flush();

        // the purge also wiped the categories the migration created, the seed file restores the full tree
        $this->seeder->seedCategories();
        $this->seeder->seedProducts();
    }
}
