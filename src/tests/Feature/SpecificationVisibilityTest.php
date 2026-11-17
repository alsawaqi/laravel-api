<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpecificationVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('Products_Sub_Sub_Department_T', function (Blueprint $t) {
            $t->id();
            $t->string('Slug');
            $t->string('Product_Sub_Sub_Department_Name');
        });
        Schema::create('Product_Specification_Description_T', function (Blueprint $t) {
            $t->id();
            $t->integer('product_sub_sub_department_id');
            $t->string('Product_Specification_Description_Name');
            $t->boolean('is_active');
            $t->boolean('show_in_table');
            $t->integer('sort_order')->nullable();
        });
        Schema::create('Product_Specification_Value_T', function (Blueprint $t) {
            $t->id();
            $t->integer('product_specification_description_id');
            $t->string('value');
        });
        Schema::create('Product_Specification_Product_T', function (Blueprint $t) {
            $t->id();
            $t->integer('Product_Id');
            $t->integer('Product_Specification_Description_Id');
            $t->integer('product_specification_value_id');
        });
        Schema::create('Products_Master_T', function (Blueprint $t) {
            $t->id();
            $t->integer('Product_Sub_Sub_Department_Id');
            $t->string('Product_Name');
            $t->string('Slug');
            $t->decimal('Product_Price', 18, 3);
            $t->boolean('Is_Active');
            $t->softDeletes();
        });
        Schema::create('Products_Images_T', function (Blueprint $t) {
            $t->id();
            $t->integer('Products_Id');
        });
        DB::table('Products_Sub_Sub_Department_T')->insert(['id' => 1, 'Slug' => 'motors', 'Product_Sub_Sub_Department_Name' => 'Motors']);
        foreach ([[true, false, 'Filter only'], [false, true, 'Table only'], [true, true, 'Both'], [false, false, 'Neither']] as $i => [$filters,$table,$name]) {
            $id = $i + 1;
            DB::table('Product_Specification_Description_T')->insert(['id' => $id, 'product_sub_sub_department_id' => 1, 'Product_Specification_Description_Name' => $name, 'is_active' => $filters, 'show_in_table' => $table, 'sort_order' => $id]);
            DB::table('Product_Specification_Value_T')->insert(['id' => $id, 'product_specification_description_id' => $id, 'value' => 'Value '.$id]);
            DB::table('Product_Specification_Product_T')->insert(['Product_Id' => 1, 'Product_Specification_Description_Id' => $id, 'product_specification_value_id' => $id]);
        }
        foreach ([1, 2] as $id) {
            DB::table('Products_Master_T')->insert(['id' => $id, 'Product_Sub_Sub_Department_Id' => 1, 'Product_Name' => 'Motor '.$id, 'Slug' => 'motor-'.$id, 'Product_Price' => 10, 'Is_Active' => true]);
        }
    }

    public function test_filter_options_and_table_columns_are_independent(): void
    {
        $filters = $this->getJson('/api/subsubdepartments/motors')->assertOk()->json('filters');
        $this->assertSame([1, 3], array_column($filters, 'id'));
        $table = $this->getJson('/api/products/motors')->assertOk();
        $this->assertSame([2, 3], array_column($table->json('headers'), 'id'));
        $this->assertSame([2, 3], array_keys($table->json('products.0.specs')));
        $table->assertJsonCount(2, 'products');
    }

    public function test_filter_only_specification_still_filters_products_when_hidden_from_table(): void
    {
        $response = $this->getJson('/api/products/motors?'.http_build_query(['filters' => json_encode([1 => [1]])]))->assertOk();
        $response->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', 1);
        $this->assertSame([2, 3], array_column($response->json('headers'), 'id'));
        $this->assertArrayNotHasKey(1, $response->json('products.0.specs'));
    }
}
