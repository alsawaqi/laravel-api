<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class StorefrontProductSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is not installed.');
        }

        config([
            'database.default' => 'catalog_search_test',
            'database.connections.catalog_search_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('catalog_search_test');

        Schema::create('Products_Departments_T', function (Blueprint $table): void {
            $table->id();
            $table->string('Product_Department_Name');
            $table->string('Product_Department_Name_Ar')->nullable();
        });

        Schema::create('Products_Sub_Department_T', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('Products_Departments_Id');
            $table->string('Sub_Department_Name');
            $table->string('Sub_Department_Name_Ar')->nullable();
        });

        Schema::create('Products_Sub_Sub_Department_T', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('Product_Sub_Department_Id');
            $table->string('Product_Sub_Sub_Department_Name');
            $table->string('Product_Sub_Sub_Department_Name_Ar')->nullable();
            $table->string('Product_Sub_Sub_Department_Code')->nullable();
            $table->string('Image_Path')->nullable();
            $table->string('Slug')->nullable();
        });

        Schema::create('Products_Master_T', function (Blueprint $table): void {
            $table->id();
            $table->string('Slug')->nullable();
            $table->string('Product_Name');
            $table->string('Product_Name_Ar')->nullable();
            $table->text('Product_Description')->nullable();
            $table->string('Product_Sku')->nullable();
            $table->string('Product_Code')->nullable();
            $table->string('Status')->default('active');
            $table->unsignedBigInteger('Product_Department_Id')->nullable();
            $table->unsignedBigInteger('Product_Sub_Department_Id')->nullable();
            $table->unsignedBigInteger('Product_Sub_Sub_Department_Id')->nullable();
            $table->boolean('Is_Active')->default(true);
            $table->softDeletes();
        });
    }

    public function test_it_ranks_titles_before_mixed_and_description_matches_and_hides_unavailable_products(): void
    {
        $this->product(1, 'Brushless Concrete Drill');
        $this->product(2, 'Brushless Concrete Drill Kit');
        $this->product(3, 'Pro Brushless Concrete Drill');
        $this->product(4, 'Drill for Concrete, Brushless');
        $this->product(5, 'Brushless Drill', ['Product_Description' => 'Designed for concrete and masonry']);
        $this->product(6, 'Workshop Kit', ['Product_Description' => 'Brushless motor to drill into concrete']);
        $this->product(7, 'Concrete Drill');
        $this->product(8, 'Brushless Concrete Drill', ['Is_Active' => false]);
        $this->product(9, 'Brushless Concrete Drill', ['deleted_at' => '2026-01-01 00:00:00']);
        $this->product(10, 'Brushless Concrete Drill', ['Slug' => null]);

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($this->search("  Brushless  Concrete\tDrill "), 'id'));
    }

    public function test_the_best_title_match_is_ranked_before_the_database_limits_candidates(): void
    {
        foreach (range(1, 12) as $id) {
            $this->product($id, "Accessory $id", ['Product_Description' => 'Cordless drill with a brushless motor']);
        }
        $this->product(13, 'Workshop Brushless Cordless Drill');

        $this->assertSame([13], array_column($this->search('cordless brushless drill', limit: 1), 'id'));
    }

    public function test_it_matches_arabic_title_and_description_words(): void
    {
        $this->product(1, 'Cordless drill', [
            'Product_Name_Ar' => 'مثقاب لاسلكي',
            'Product_Description' => 'مناسب للخرسانة',
        ]);
        $this->product(2, 'Hand drill', ['Product_Name_Ar' => 'مثقاب يدوي']);

        $matches = $this->search('خرسانة مثقاب');

        $this->assertSame([1], array_column($matches, 'id'));
        $this->assertSame('مثقاب لاسلكي', $matches[0]['Search_Title_Ar']);
        $this->assertSame('/product/product-1', $matches[0]['Route_Path']);
    }

    public function test_identifier_search_treats_pattern_characters_as_literals(): void
    {
        $this->product(1, 'Caliper', ['Product_Sku' => 'AB_[9]!%', 'Product_Code' => 'CAL-001']);
        $this->product(2, 'Bracket', ['Product_Sku' => 'ABX9!100']);

        $this->assertSame([1], array_column($this->search('AB_[9]!%'), 'id'));
        $this->assertSame([1], array_column($this->search('CAL-001'), 'id'));
        $this->assertSame([], $this->search('%%'));
        $this->assertSame([], $this->search('q'));
    }

    public function test_category_suggestions_and_products_linked_to_the_category_still_work(): void
    {
        DB::table('Products_Departments_T')->insert(['id' => 1, 'Product_Department_Name' => 'Tools']);
        DB::table('Products_Sub_Department_T')->insert([
            'id' => 2, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Accessories',
        ]);
        DB::table('Products_Sub_Sub_Department_T')->insert([
            'id' => 3, 'Product_Sub_Department_Id' => 2,
            'Product_Sub_Sub_Department_Name' => 'Concrete Drill Bits', 'Slug' => 'concrete-drill-bits',
        ]);
        $this->product(1, 'Masonry bit', [
            'Product_Department_Id' => 1, 'Product_Sub_Department_Id' => 2, 'Product_Sub_Sub_Department_Id' => 3,
        ]);

        $matches = $this->search('Drill Bits');

        $this->assertSame(['category', 'product'], array_column($matches, 'Result_Type'));
        $this->assertSame('/departments/concrete-drill-bits', $matches[0]['Route_Path']);
        $this->assertSame(['deptId' => '1', 'subId' => '2', 'subSubId' => '3'], $matches[0]['Route_Query']);
    }

    private function product(int $id, string $name, array $attributes = []): void
    {
        DB::table('Products_Master_T')->insert(array_replace([
            'id' => $id,
            'Slug' => "product-$id",
            'Product_Name' => $name,
        ], $attributes));
    }

    private function search(string $term, int $limit = 50): array
    {
        return $this->getJson('/api/search/products?'.http_build_query(['q' => $term, 'limit' => $limit]))
            ->assertOk()
            ->json();
    }
}
