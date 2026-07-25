<?php

declare(strict_types=1);

namespace Tests\Unit\Payments\Thawani;

use App\Models\CustomersContact;
use App\Models\CustomersMaster;
use App\Models\OrdersPlaced;
use App\Models\User;
use App\Services\Payments\Thawani\ThawaniMetadataBuilder;
use InvalidArgumentException;
use Tests\TestCase;

class ThawaniMetadataBuilderTest extends TestCase
{
    public function test_it_builds_only_the_approved_customer_and_order_metadata(): void
    {
        $user = new User([
            'User_Name' => 'Fallback Name',
            'email' => 'Customer@Example.com',
            'password' => 'must-never-be-exported',
        ]);
        $customer = new CustomersMaster([
            'Customer_Code' => 'CUS-1001',
            'Customer_Full_Name' => 'Test Customer',
            'Telephone_Country_Code' => '+968',
            'Telephone' => '9123 4567',
        ]);
        $order = new OrdersPlaced([
            'Order_Code' => 'ORD-2026-0001',
            'Total_Price' => '16.050',
        ]);

        $metadata = (new ThawaniMetadataBuilder)->build($user, $customer, $order);

        $this->assertSame([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+96891234567',
            'customer_id' => 'CUS-1001',
            'order_id' => 'ORD-2026-0001',
        ], $metadata);
        $this->assertSame([
            'customer_name',
            'customer_email',
            'customer_phone',
            'customer_id',
            'order_id',
        ], array_keys($metadata));
    }

    public function test_it_uses_an_authorized_contact_as_a_field_fallback(): void
    {
        $user = new User([
            'User_Name' => 'Fallback Name',
            'email' => 'customer@example.com',
        ]);
        $customer = new CustomersMaster([
            'Customer_Code' => 'CUS-1002',
        ]);
        $order = new OrdersPlaced([
            'Order_Code' => 'ORD-2026-0002',
        ]);
        $contact = new CustomersContact([
            'Contact_Person_Name' => 'Contact Name',
            'Telephone_Country_Code' => '968',
            'Gsm' => '+968 9911 2233',
        ]);

        $metadata = (new ThawaniMetadataBuilder)->build($user, $customer, $order, $contact);

        $this->assertSame('Contact Name', $metadata['customer_name']);
        $this->assertSame('+96899112233', $metadata['customer_phone']);
    }

    public function test_it_rejects_missing_required_customer_metadata(): void
    {
        $user = new User([
            'User_Name' => 'Test Customer',
            'email' => 'customer@example.com',
        ]);
        $customer = new CustomersMaster([
            'Customer_Code' => 'CUS-1003',
            'Customer_Full_Name' => 'Test Customer',
        ]);
        $order = new OrdersPlaced([
            'Order_Code' => 'ORD-2026-0003',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A customer phone is required for Thawani checkout.');

        (new ThawaniMetadataBuilder)->build($user, $customer, $order);
    }

    public function test_it_rejects_an_invalid_email_address(): void
    {
        $user = new User([
            'User_Name' => 'Test Customer',
            'email' => 'not-an-email',
        ]);
        $customer = new CustomersMaster([
            'Customer_Code' => 'CUS-1004',
            'Customer_Full_Name' => 'Test Customer',
            'Telephone_Country_Code' => '+968',
            'Telephone' => '91234567',
        ]);
        $order = new OrdersPlaced([
            'Order_Code' => 'ORD-2026-0004',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A valid customer email is required for Thawani checkout.');

        (new ThawaniMetadataBuilder)->build($user, $customer, $order);
    }
}
