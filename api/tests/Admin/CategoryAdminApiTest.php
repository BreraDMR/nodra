<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\Category;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CategoryAdminApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginAdmin();
    }

    public function testCreateListAndRejectADuplicateSlug(): void
    {
        $rootId = $this->create('t-drive', attributes: [$this->attribute('speeds', 'number', unit: 'x')]);
        $childId = $this->create('t-chains', $rootId, [$this->attribute('material', 'choice', options: [['value' => 'steel', 'labelCs' => 'Ocel'], 'ti'])]);

        $this->sendJson('POST', '/api/admin/categories', $this->payload('t-drive'), $this->token);
        self::assertResponseStatusCodeSame(409);

        $list = array_column($this->getJson('/api/admin/categories'), null, 'slug');
        self::assertSame($rootId, $list['t-chains']['parentId']);
        self::assertSame(1, $list['t-chains']['depth']);
        self::assertSame(['material'], array_column($list['t-chains']['attributes'], 'key'));
        self::assertSame(['speeds', 'material'], array_column($list['t-chains']['effectiveAttributes'], 'key'));
        self::assertSame([
            ['value' => 'steel', 'labelCs' => 'Ocel', 'labelDe' => null, 'labelEn' => null],
            ['value' => 'ti', 'labelCs' => null, 'labelDe' => null, 'labelEn' => null],
        ], $list['t-chains']['attributes'][0]['options']);
        self::assertSame($childId, $list['t-chains']['id']);
    }

    public function testCategoryCannotMoveUnderItselfOrItsDescendant(): void
    {
        $rootId = $this->create('t-drive');
        $childId = $this->create('t-chains', $rootId);
        $grandchildId = $this->create('t-pins', $childId);

        foreach ([$rootId, $childId, $grandchildId] as $target) {
            $this->sendJson('PUT', '/api/admin/categories/'.$rootId, $this->payload('t-drive', $target), $this->token);
            self::assertResponseStatusCodeSame(409);
        }
        self::assertNull($this->db()->fetchOne('SELECT parent_id FROM category WHERE id = :id', ['id' => $rootId]));
    }

    public function testAttributeKeysStayUniqueAlongTheBranch(): void
    {
        $rootId = $this->create('t-drive', attributes: [$this->attribute('speeds', 'number')]);
        $childId = $this->create('t-chains', $rootId, [$this->attribute('links', 'number')]);

        // the parent already has it
        $this->sendJson('POST', '/api/admin/categories', $this->payload('t-cassettes', $rootId, [$this->attribute('speeds', 'number')]), $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('PUT', '/api/admin/categories/'.$childId, $this->payload('t-chains', $rootId, [$this->attribute('links', 'number'), $this->attribute('speeds', 'text')]), $this->token);
        self::assertResponseStatusCodeSame(422);
        // a subcategory already has it
        $this->sendJson('PUT', '/api/admin/categories/'.$rootId, $this->payload('t-drive', null, [$this->attribute('speeds', 'number'), $this->attribute('links', 'number')]), $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('t-chains', $this->client->getResponse()->getContent());
        // twice in the same list
        $this->sendJson('POST', '/api/admin/categories', $this->payload('t-cables', null, [$this->attribute('kind', 'text'), $this->attribute('kind', 'text')]), $this->token);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(['speeds'], array_column(json_decode($this->db()->fetchOne('SELECT attributes FROM category WHERE id = :id', ['id' => $rootId]), true), 'key'));
    }

    public function testMovingABranchUnderACategoryWithTheSameKeyIsRejected(): void
    {
        $speedsRoot = $this->create('t-drive', attributes: [$this->attribute('speeds', 'number')]);
        $looseId = $this->create('t-loose');
        $this->create('t-loose-chains', $looseId, [$this->attribute('speeds', 'number')]);

        // t-loose itself has no attributes, but its child would end up with speeds twice
        $this->sendJson('PUT', '/api/admin/categories/'.$looseId, $this->payload('t-loose', $speedsRoot), $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->db()->fetchOne('SELECT parent_id FROM category WHERE id = :id', ['id' => $looseId]));
    }

    public function testDefinitionsAreValidated(): void
    {
        $cases = [
            'choice without options' => $this->attribute('bead', 'choice'),
            'unknown type' => $this->attribute('bead', 'colour'),
            'bad key' => $this->attribute('Bead Type', 'text'),
            'missing label' => ['key' => 'bead', 'type' => 'text', 'labelCs' => 'Patka', 'labelDe' => '', 'labelEn' => 'Bead', 'filterable' => false],
            'repeated option' => $this->attribute('bead', 'choice', options: ['wire', 'wire']),
        ];
        foreach ($cases as $case => $definition) {
            $this->sendJson('POST', '/api/admin/categories', $this->payload('t-tyres', null, [$definition]), $this->token);
            self::assertResponseStatusCodeSame(422, $case);
        }
        self::assertFalse($this->db()->fetchOne("SELECT id FROM category WHERE slug = 't-tyres'"));
    }

    public function testDeactivationIsBlockedByPublishedProductsBelow(): void
    {
        $rootId = $this->create('t-drive');
        $childId = $this->create('t-chains', $rootId);
        $emptyId = $this->create('t-empty', $rootId);
        $b = $this->builder();
        $b->sellable('t-chain', $b->category('t-chain-pins', $this->entity($childId)));
        $b->sellable('t-draft', $this->entity($emptyId), status: 'draft');

        $this->sendJson('PUT', '/api/admin/categories/'.$rootId, $this->payload('t-drive', active: false), $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('1 published', $this->client->getResponse()->getContent());

        // only a draft there, so it may go
        $this->sendJson('PUT', '/api/admin/categories/'.$emptyId, $this->payload('t-empty', $rootId, active: false), $this->token);
        self::assertResponseIsSuccessful();
        $list = array_column($this->getJson('/api/admin/categories'), null, 'slug');
        self::assertTrue($list['t-drive']['active']);
        self::assertFalse($list['t-empty']['active']);
        self::assertSame(1, $list['t-empty']['productCount']);

        $public = array_column($this->getJson('/api/categories'), null, 'slug');
        self::assertSame(['t-chains'], array_column($public['t-drive']['children'], 'slug'));
    }

    public function testUnknownCategoryIsNotFound(): void
    {
        $this->sendJson('PUT', '/api/admin/categories/01890000-0000-7000-8000-000000000000', $this->payload('t-ghost'), $this->token);
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('POST', '/api/admin/categories', $this->payload('t-orphan', '01890000-0000-7000-8000-000000000000'), $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    private function create(string $slug, ?string $parentId = null, array $attributes = []): string
    {
        $created = $this->sendJson('POST', '/api/admin/categories', $this->payload($slug, $parentId, $attributes), $this->token);
        self::assertResponseStatusCodeSame(201);

        return $created['id'];
    }

    private function payload(string $slug, ?string $parentId = null, array $attributes = [], bool $active = true): array
    {
        return ['slug' => $slug, 'parentId' => $parentId, 'nameCs' => $slug.' cs', 'nameDe' => $slug.' de', 'nameEn' => $slug.' en', 'position' => 0, 'active' => $active, 'attributes' => $attributes];
    }

    /** Admin form shape: flat labelCs/labelDe/labelEn. */
    private function attribute(string $key, string $type, array $options = [], ?string $unit = null): array
    {
        return ['key' => $key, 'type' => $type, 'unit' => $unit, 'labelCs' => $key.' cs', 'labelDe' => $key.' de', 'labelEn' => $key.' en', 'filterable' => true, 'options' => $options];
    }

    private function entity(string $id): Category
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(Category::class, $id);
    }
}
