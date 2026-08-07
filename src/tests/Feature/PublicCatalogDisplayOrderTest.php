<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class PublicCatalogDisplayOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is not installed.');
        }

        config([
            'cache.default' => 'array',
            'database.default' => 'public_catalog_display_order_test',
            'database.connections.public_catalog_display_order_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('public_catalog_display_order_test');
        DB::setDefaultConnection('public_catalog_display_order_test');
        Cache::flush();

        $this->createHierarchySchema();
    }

    protected function tearDown(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            Cache::flush();
            Schema::dropIfExists('Products_Sub_Sub_Department_T');
            Schema::dropIfExists('Products_Sub_Department_T');
            Schema::dropIfExists('Products_Departments_T');
            Schema::dropIfExists('Product_Hierarchy_Display_Order_State_T');
            DB::purge('public_catalog_display_order_test');
        }

        parent::tearDown();
    }

    public function test_every_public_hierarchy_level_uses_display_order_with_deterministic_fallbacks(): void
    {
        DB::table('Products_Departments_T')->insert([
            ['id' => 1, 'Product_Department_Name' => 'Explicit second', 'Display_Order' => 20, 'Source_Main_Sequence' => 1],
            ['id' => 2, 'Product_Department_Name' => 'Explicit first', 'Display_Order' => 10, 'Source_Main_Sequence' => 99],
            ['id' => 3, 'Product_Department_Name' => 'Source second', 'Display_Order' => null, 'Source_Main_Sequence' => 2],
            ['id' => 4, 'Product_Department_Name' => 'Source first', 'Display_Order' => null, 'Source_Main_Sequence' => 1],
            ['id' => 5, 'Product_Department_Name' => 'Zulu fallback', 'Display_Order' => null, 'Source_Main_Sequence' => null],
            ['id' => 6, 'Product_Department_Name' => 'Alpha fallback', 'Display_Order' => null, 'Source_Main_Sequence' => null],
            ['id' => 7, 'Product_Department_Name' => 'Alpha fallback', 'Display_Order' => null, 'Source_Main_Sequence' => null],
        ]);

        DB::table('Products_Sub_Department_T')->insert([
            ['id' => 11, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Explicit second', 'Display_Order' => 20, 'Source_Sub_Sequence' => 1],
            ['id' => 12, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Explicit first', 'Display_Order' => 10, 'Source_Sub_Sequence' => 99],
            ['id' => 13, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Source second', 'Display_Order' => null, 'Source_Sub_Sequence' => 2],
            ['id' => 14, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Source first', 'Display_Order' => null, 'Source_Sub_Sequence' => 1],
            ['id' => 15, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Zulu fallback', 'Display_Order' => null, 'Source_Sub_Sequence' => null],
            ['id' => 16, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Alpha fallback', 'Display_Order' => null, 'Source_Sub_Sequence' => null],
        ]);

        DB::table('Products_Sub_Sub_Department_T')->insert([
            ['id' => 21, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Explicit second', 'Display_Order' => 20, 'Source_Sub_Sub_Sequence' => 1],
            ['id' => 22, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Explicit first', 'Display_Order' => 10, 'Source_Sub_Sub_Sequence' => 99],
            ['id' => 23, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Source second', 'Display_Order' => null, 'Source_Sub_Sub_Sequence' => 2],
            ['id' => 24, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Source first', 'Display_Order' => null, 'Source_Sub_Sub_Sequence' => 1],
            ['id' => 25, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Zulu fallback', 'Display_Order' => null, 'Source_Sub_Sub_Sequence' => null],
            ['id' => 26, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Alpha fallback', 'Display_Order' => null, 'Source_Sub_Sub_Sequence' => null],
        ]);

        $this->assertSame([2, 1, 4, 3, 6, 7, 5], $this->responseIds('/api/productdepartment'));
        $this->assertSame([12, 11, 14, 13, 16, 15], $this->responseIds('/api/categories/1/subcategories'));
        $this->assertSame([22, 21, 24, 23, 26, 25], $this->responseIds('/api/subcategories/11/subsubcategories'));
    }

    public function test_source_and_name_ordering_supports_deployment_before_display_order_columns_exist(): void
    {
        Schema::table('Products_Departments_T', fn (Blueprint $table) => $table->dropColumn('Display_Order'));
        Schema::table('Products_Sub_Department_T', fn (Blueprint $table) => $table->dropColumn('Display_Order'));
        Schema::table('Products_Sub_Sub_Department_T', fn (Blueprint $table) => $table->dropColumn('Display_Order'));

        DB::table('Products_Departments_T')->insert([
            ['id' => 1, 'Product_Department_Name' => 'Source second', 'Source_Main_Sequence' => 2],
            ['id' => 2, 'Product_Department_Name' => 'Source first', 'Source_Main_Sequence' => 1],
        ]);
        DB::table('Products_Sub_Department_T')->insert([
            ['id' => 11, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Source second', 'Source_Sub_Sequence' => 2],
            ['id' => 12, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Source first', 'Source_Sub_Sequence' => 1],
        ]);
        DB::table('Products_Sub_Sub_Department_T')->insert([
            ['id' => 21, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Source second', 'Source_Sub_Sub_Sequence' => 2],
            ['id' => 22, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Source first', 'Source_Sub_Sub_Sequence' => 1],
        ]);

        $this->assertSame([2, 1], $this->responseIds('/api/productdepartment'));
        $this->assertSame([12, 11], $this->responseIds('/api/categories/1/subcategories'));
        $this->assertSame([22, 21], $this->responseIds('/api/subcategories/11/subsubcategories'));
    }

    public function test_name_and_id_ordering_supports_the_legacy_schema(): void
    {
        Schema::table('Products_Departments_T', function (Blueprint $table): void {
            $table->dropColumn(['Display_Order', 'Source_Main_Sequence']);
        });
        Schema::table('Products_Sub_Department_T', function (Blueprint $table): void {
            $table->dropColumn(['Display_Order', 'Source_Sub_Sequence']);
        });
        Schema::table('Products_Sub_Sub_Department_T', function (Blueprint $table): void {
            $table->dropColumn(['Display_Order', 'Source_Sub_Sub_Sequence']);
        });

        DB::table('Products_Departments_T')->insert([
            ['id' => 1, 'Product_Department_Name' => 'Zulu'],
            ['id' => 3, 'Product_Department_Name' => 'Alpha'],
            ['id' => 2, 'Product_Department_Name' => 'Alpha'],
        ]);
        DB::table('Products_Sub_Department_T')->insert([
            ['id' => 11, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Zulu'],
            ['id' => 13, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Alpha'],
            ['id' => 12, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'Alpha'],
        ]);
        DB::table('Products_Sub_Sub_Department_T')->insert([
            ['id' => 21, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Zulu'],
            ['id' => 23, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Alpha'],
            ['id' => 22, 'Product_Sub_Department_Id' => 11, 'Product_Sub_Sub_Department_Name' => 'Alpha'],
        ]);

        $this->assertSame([2, 3, 1], $this->responseIds('/api/productdepartment'));
        $this->assertSame([12, 13, 11], $this->responseIds('/api/categories/1/subcategories'));
        $this->assertSame([22, 23, 21], $this->responseIds('/api/subcategories/11/subsubcategories'));
    }

    public function test_revision_one_is_used_when_the_display_order_state_row_or_table_is_unavailable(): void
    {
        DB::table('Products_Departments_T')->insert([
            'id' => 1,
            'Product_Department_Name' => 'Tools',
            'Display_Order' => 1,
            'Source_Main_Sequence' => 1,
        ]);

        DB::table('Product_Hierarchy_Display_Order_State_T')->where('id', 1)->delete();
        $this->getJson('/api/productdepartment')->assertOk()->assertJsonCount(1);
        $this->assertTrue(Cache::has('storefront:catalog:departments:v2:r1'));

        Cache::flush();
        Schema::drop('Product_Hierarchy_Display_Order_State_T');

        $this->getJson('/api/productdepartment')->assertOk()->assertJsonCount(1);
        $this->assertTrue(Cache::has('storefront:catalog:departments:v2:r1'));
    }

    private function createHierarchySchema(): void
    {
        Schema::create('Products_Departments_T', function (Blueprint $table): void {
            $table->id();
            $table->string('Product_Department_Name');
            $table->string('Product_Department_Name_Ar')->nullable();
            $table->string('Image_path')->nullable();
            $table->bigInteger('Display_Order')->nullable();
            $table->unsignedInteger('Source_Main_Sequence')->nullable();
        });

        Schema::create('Products_Sub_Department_T', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('Products_Departments_Id');
            $table->string('Sub_Department_Name');
            $table->string('Sub_Department_Name_Ar')->nullable();
            $table->string('Image_path')->nullable();
            $table->bigInteger('Display_Order')->nullable();
            $table->unsignedInteger('Source_Sub_Sequence')->nullable();
        });

        Schema::create('Products_Sub_Sub_Department_T', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('Product_Sub_Department_Id');
            $table->string('Product_Sub_Sub_Department_Name');
            $table->string('Product_Sub_Sub_Department_Name_Ar')->nullable();
            $table->string('Image_Path')->nullable();
            $table->string('Slug')->nullable();
            $table->bigInteger('Display_Order')->nullable();
            $table->unsignedInteger('Source_Sub_Sub_Sequence')->nullable();
        });

        Schema::create('Product_Hierarchy_Display_Order_State_T', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->bigInteger('Revision')->default(1);
        });

        DB::table('Product_Hierarchy_Display_Order_State_T')->insert([
            'id' => 1,
            'Revision' => 1,
        ]);
    }

    /** @return list<int> */
    private function responseIds(string $uri): array
    {
        return collect($this->getJson($uri)->assertOk()->json())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
