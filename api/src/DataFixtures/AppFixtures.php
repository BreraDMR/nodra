<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AdminUser;
use App\Entity\Product;
use App\Entity\ProductVariant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher) {}

    public function load(ObjectManager $manager): void
    {
        $admin = new AdminUser('admin@nodra.test', '', 'NODRA Studio');
        $manager->persist(new AdminUser(
            'admin@nodra.test',
            $this->passwordHasher->hashPassword($admin, 'NodraDemo2026!'),
            'NODRA Studio',
        ));

        $products = json_decode(file_get_contents(__DIR__.'/../../data/products.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($products as $rank => $item) {
            $copy = [];
            foreach (['cs', 'de', 'en'] as $locale) {
                $copy[$locale] = [
                    'name' => $item['name'][$locale],
                    'short' => $item['short'][$locale],
                    'description' => $item['description'][$locale] ?? $item['short'][$locale],
                    'details' => $item['details'][$locale] ?? $this->details($item['category'], $locale),
                ];
            }

            $image = '/images/'.$item['image'];
            $product = new Product($item['slug'], $item['category'], $copy, $image);
            $product->update($item['slug'], $item['category'], $copy, $image, [$image], $item['badge'], $rank + 1, 'published');
            $manager->persist($product);

            foreach ($item['variants'] as $index => $option) {
                $manager->persist(new ProductVariant(
                    $product,
                    'ND-'.strtoupper(str_replace('-', '', substr($item['slug'], 0, 10))).'-'.($index + 1),
                    $option['label'],
                    $item['priceCzk'] + ($option['priceDeltaCzk'] ?? 0),
                    $item['priceEur'] + ($option['priceDeltaEur'] ?? 0),
                    max(0, $item['stock'] - ($index * 3)),
                    $option['color'] ?? null,
                    $option['size'] ?? null,
                ));
            }
        }

        $manager->flush();
    }

    /** @return list<string> */
    private function details(string $category, string $locale): array
    {
        $details = [
            'bags' => [
                'cs' => ['Odolná tkanina do proměnlivého počasí', 'Promyšlené kapsy pro každodenní výbavu', 'Jednoduché uchycení na kolo'],
                'de' => ['Robustes Material für wechselndes Wetter', 'Durchdachte Fächer für Alltagsgepäck', 'Einfache Befestigung am Rad'],
                'en' => ['Durable fabric for changing weather', 'Considered storage for daily essentials', 'Easy to fit to your bike'],
            ],
            'apparel' => [
                'cs' => ['Střih navržený pro pohyb na kole', 'Pohodlné vrstvení v každém ročním období', 'Nenápadné reflexní detaily'],
                'de' => ['Schnitt für Bewegung auf dem Rad', 'Bequemes Layering zu jeder Jahreszeit', 'Dezente reflektierende Details'],
                'en' => ['A cut shaped for movement on the bike', 'Comfortable layering across seasons', 'Subtle reflective details'],
            ],
            'lights' => [
                'cs' => ['Kompaktní tvar pro městské jízdy', 'Snadné nasazení a sejmutí', 'Čistý, funkční design'],
                'de' => ['Kompakte Form für Stadtfahrten', 'Schnell angebracht und abgenommen', 'Klares, funktionales Design'],
                'en' => ['Compact form for city rides', 'Quick to fit and remove', 'Clean, functional design'],
            ],
            'accessories' => [
                'cs' => ['Praktický doplněk pro každodenní jízdu', 'Příjemný materiál a jednoduchá údržba', 'Navrženo pro dlouhé používání'],
                'de' => ['Praktisches Detail für tägliche Fahrten', 'Angenehmes Material und einfache Pflege', 'Für viele Fahrten gemacht'],
                'en' => ['A practical detail for everyday rides', 'Comfortable material and easy care', 'Designed for many miles'],
            ],
        ];

        return $details[$category][$locale];
    }
}
