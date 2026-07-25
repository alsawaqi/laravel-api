<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('Products_Sub_Sub_Department_T', function (Blueprint $table): void {
        $table->id();
        $table->string('Slug')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('Products_Master_T', function (Blueprint $table): void {
        $table->id();
        $table->string('Slug')->nullable();
        $table->unsignedBigInteger('Product_Sub_Sub_Department_Id');
        $table->boolean('Is_Active')->default(true);
        $table->timestamp('deleted_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('Products_Master_T');
    Schema::dropIfExists('Products_Sub_Sub_Department_T');
});

it('returns only indexable category and product slugs with their real timestamps', function () {
    DB::table('Products_Sub_Sub_Department_T')->insert([
        [
            'id' => 1,
            'Slug' => 'bearings',
            'updated_at' => '2026-07-20 10:15:30',
        ],
        [
            'id' => 2,
            'Slug' => 'empty-category',
            'updated_at' => '2026-07-21 11:00:00',
        ],
        [
            'id' => 3,
            'Slug' => 'inactive-products-only',
            'updated_at' => '2026-07-22 12:00:00',
        ],
        [
            'id' => 4,
            'Slug' => '',
            'updated_at' => '2026-07-23 13:00:00',
        ],
    ]);

    DB::table('Products_Master_T')->insert([
        [
            'id' => 1,
            'Slug' => 'bearing-6204',
            'Product_Sub_Sub_Department_Id' => 1,
            'Is_Active' => true,
            'deleted_at' => null,
            'updated_at' => '2026-07-24 08:30:45',
        ],
        [
            'id' => 2,
            'Slug' => 'inactive-bearing',
            'Product_Sub_Sub_Department_Id' => 3,
            'Is_Active' => false,
            'deleted_at' => null,
            'updated_at' => '2026-07-24 09:00:00',
        ],
        [
            'id' => 3,
            'Slug' => 'deleted-bearing',
            'Product_Sub_Sub_Department_Id' => 1,
            'Is_Active' => true,
            'deleted_at' => '2026-07-24 09:30:00',
            'updated_at' => '2026-07-24 09:30:00',
        ],
        [
            'id' => 4,
            'Slug' => '',
            'Product_Sub_Sub_Department_Id' => 2,
            'Is_Active' => true,
            'deleted_at' => null,
            'updated_at' => '2026-07-24 10:00:00',
        ],
    ]);

    $response = $this->getJson('/api/seo/sitemap');

    $response
        ->assertOk()
        ->assertExactJson([
            'categories' => [
                [
                    'slug' => 'bearings',
                    'updated_at' => '2026-07-20T10:15:30+00:00',
                ],
            ],
            'products' => [
                [
                    'slug' => 'bearing-6204',
                    'updated_at' => '2026-07-24T08:30:45+00:00',
                ],
            ],
        ]);

    expect($response->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('max-age=300')
        ->toContain('s-maxage=3600')
        ->toContain('stale-while-revalidate=86400');
});

it('uses a constant two queries regardless of catalog size', function () {
    DB::table('Products_Sub_Sub_Department_T')->insert([
        'id' => 1,
        'Slug' => 'motors',
        'updated_at' => '2026-07-20 10:15:30',
    ]);

    DB::table('Products_Master_T')->insert(
        collect(range(1, 75))
            ->map(fn (int $id): array => [
                'id' => $id,
                'Slug' => "motor-{$id}",
                'Product_Sub_Sub_Department_Id' => 1,
                'Is_Active' => true,
                'deleted_at' => null,
                'updated_at' => '2026-07-24 08:30:45',
            ])
            ->all()
    );

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->getJson('/api/seo/sitemap');
    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    $response
        ->assertOk()
        ->assertJsonCount(1, 'categories')
        ->assertJsonCount(75, 'products');

    expect($queryCount)->toBe(2);
});
