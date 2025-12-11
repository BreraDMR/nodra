<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

final class CatalogService
{
    private const CATEGORIES = [
        'bags' => ['cs' => 'Brašny', 'de' => 'Taschen', 'en' => 'Bags'],
        'apparel' => ['cs' => 'Oblečení', 'de' => 'Bekleidung', 'en' => 'Apparel'],
        'lights' => ['cs' => 'Světla', 'de' => 'Beleuchtung', 'en' => 'Lights'],
        'accessories' => ['cs' => 'Doplňky', 'de' => 'Zubehör', 'en' => 'Accessories'],
    ];
}
