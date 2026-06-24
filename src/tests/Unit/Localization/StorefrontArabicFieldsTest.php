<?php

namespace Tests\Unit\Localization;

use App\Support\Localization\StorefrontArabicFields;
use Tests\TestCase;

class StorefrontArabicFieldsTest extends TestCase
{
    public function test_category_selects_include_arabic_name_fields(): void
    {
        $this->assertContains('Product_Department_Name_Ar', StorefrontArabicFields::departmentSelect());
        $this->assertContains('Sub_Department_Name_Ar', StorefrontArabicFields::subDepartmentSelect());
        $this->assertContains('Product_Sub_Sub_Department_Name_Ar', StorefrontArabicFields::subSubDepartmentSelect());
    }

    public function test_product_search_selects_include_arabic_product_and_category_fields(): void
    {
        $fields = StorefrontArabicFields::productSearchSelect();

        $this->assertContains('p.Product_Name_Ar', $fields);
        $this->assertContains('ssd.Product_Sub_Sub_Department_Name_Ar', $fields);
        $this->assertContains('sd.Sub_Department_Name_Ar', $fields);
        $this->assertContains('d.Product_Department_Name_Ar', $fields);
    }
}
