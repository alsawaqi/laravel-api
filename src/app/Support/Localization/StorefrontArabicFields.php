<?php

namespace App\Support\Localization;

class StorefrontArabicFields
{
    public static function departmentSelect(): array
    {
        return [
            'id',
            'Product_Department_Name',
            'Product_Department_Name_Ar',
            'Image_path',
        ];
    }

    public static function subDepartmentSelect(): array
    {
        return [
            'id',
            'Sub_Department_Name',
            'Sub_Department_Name_Ar',
            'Products_Departments_Id',
            'Image_path',
        ];
    }

    public static function subSubDepartmentSelect(): array
    {
        return [
            'id',
            'Product_Sub_Sub_Department_Name',
            'Product_Sub_Sub_Department_Name_Ar',
            'Product_Sub_Department_Id',
            'Image_Path',
            'Slug',
        ];
    }

    public static function categorySearchSelect(): array
    {
        return [
            'ssd.id',
            'ssd.Slug',
            'ssd.Product_Sub_Sub_Department_Name',
            'ssd.Product_Sub_Sub_Department_Name_Ar',
            'ssd.Product_Sub_Sub_Department_Code',
            'ssd.Image_Path',
            'sd.id as Product_Sub_Department_Id',
            'sd.Sub_Department_Name',
            'sd.Sub_Department_Name_Ar',
            'd.id as Product_Department_Id',
            'd.Product_Department_Name',
            'd.Product_Department_Name_Ar',
        ];
    }

    public static function productSearchSelect(): array
    {
        return [
            'p.id',
            'p.Slug',
            'p.Product_Name',
            'p.Product_Name_Ar',
            'p.Product_Sku',
            'p.Product_Code',
            'p.Status',
            'ssd.Product_Sub_Sub_Department_Name',
            'ssd.Product_Sub_Sub_Department_Name_Ar',
            'sd.Sub_Department_Name',
            'sd.Sub_Department_Name_Ar',
            'd.Product_Department_Name',
            'd.Product_Department_Name_Ar',
        ];
    }
}
