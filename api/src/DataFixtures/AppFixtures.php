<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Catalog\CatalogSeeder;
use App\Entity\AdminUser;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    /** The well-known dev password; every other environment must set its own or get a random one. */
    public const DEV_PASSWORD = 'NodraDemo2026!';

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private CatalogSeeder $seeder,
        #[Autowire(param: 'app.admin.password')] private string $adminPassword,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $admin = new AdminUser('admin@nodra.test', '', 'NODRA Studio');
        $password = self::adminPassword($this->adminPassword, $this->environment, bin2hex(random_bytes(8)));
        if ($this->adminPassword === '' && $this->environment !== 'dev') {
            // the person running the reset must learn the generated password right here
            file_put_contents('php://stderr', sprintf("Admin password for admin@nodra.test: %s\n", $password));
        }
        $manager->persist(new AdminUser(
            'admin@nodra.test',
            $this->passwordHasher->hashPassword($admin, $password),
            'NODRA Studio',
        ));
        $manager->flush();

        // the purge also wiped the categories the migration created, the seed file restores the full tree
        $this->seeder->seedCategories();
        $this->seeder->seedProducts();
    }

    /** An explicit password wins; dev keeps the well-known one; anything else takes the generated random. */
    public static function adminPassword(?string $explicit, string $environment, string $random): string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return trim($explicit);
        }
        if ($environment === 'dev') {
            return self::DEV_PASSWORD;
        }

        return $random;
    }
}
