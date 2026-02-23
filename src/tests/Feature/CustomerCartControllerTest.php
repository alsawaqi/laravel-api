<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\CustomersMaster;
use App\Models\CustomerCart;

uses(Tests\TestCase::class, RefreshDatabase::class);

function actingCustomerUser(): User {
    $user = User::factory()->create();
    // ensure related customer exists
    $user->customerOrCreate();
    return $user;
}

// 1. index returns current customer's cart items
it('returns current customer cart items', function () {
    $user = actingCustomerUser();
    $this->actingAs($user, 'api');

    $customer = $user->customerOrCreate();
    CustomerCart::create([
        'Customers_Id' => $customer->id,
        'Products_Id' => 10,
        'Quantity' => 2,
    ]);

    $response = $this->getJson('/api/cart');

    $response->assertStatus(200)
        ->assertJsonStructure(['data'])
        ->assertJsonFragment(['Products_Id' => 10, 'Quantity' => 2]);
});

// 2. sync overrides quantities for existing products and inserts missing ones
it('sync overrides or inserts items from payload', function () {
    $user = actingCustomerUser();
    $this->actingAs($user, 'api');
    $customer = $user->customerOrCreate();

    // existing row that should be overridden
    CustomerCart::create([
        'Customers_Id' => $customer->id,
        'Products_Id' => 5,
        'Quantity' => 1,
    ]);

    $payload = [
        'items' => [
            ['product_id' => 5, 'quantity' => 7],   // override
            ['product_id' => 6, 'quantity' => 3],   // insert
        ],
    ];

    $response = $this->postJson('/api/cart/merge', $payload);

    $response->assertStatus(200);

    $this->assertDatabaseHas('Customers_Carts_T', [
        'Customers_Id' => $customer->id,
        'Products_Id' => 5,
        'Quantity' => 7,
    ]);
    $this->assertDatabaseHas('Customers_Carts_T', [
        'Customers_Id' => $customer->id,
        'Products_Id' => 6,
        'Quantity' => 3,
    ]);
});

// 3. setQuantity upserts with exact quantity and requires min:1
it('sets quantity exactly and validates minimum 1', function () {
    $user = actingCustomerUser();
    $this->actingAs($user, 'api');

    // create new row
    $response = $this->patchJson('/api/cart', [
        'product_id' => 11,
        'quantity' => 4,
    ]);
    $response->assertStatus(200);

    $this->assertDatabaseHas('Customers_Carts_T', [
        'Products_Id' => 11,
        'Quantity' => 4,
    ]);

    // update existing row to exact quantity
    $response = $this->patchJson('/api/cart', [
        'product_id' => 11,
        'quantity' => 9,
    ]);
    $response->assertStatus(200);

    $this->assertDatabaseHas('Customers_Carts_T', [
        'Products_Id' => 11,
        'Quantity' => 9,
    ]);

    // invalid quantity
    $response = $this->patchJson('/api/cart', [
        'product_id' => 11,
        'quantity' => 0,
    ]);
    $response->assertStatus(422);
});

// 4. add increases quantity atomically or inserts with default 1
it('adds items increasing quantity atomically and default quantity 1', function () {
    $user = actingCustomerUser();
    $this->actingAs($user, 'api');
    $customer = $user->customerOrCreate();

    CustomerCart::create([
        'Customers_Id' => $customer->id,
        'Products_Id' => 20,
        'Quantity' => 2,
    ]);

    // increase existing
    $response = $this->postJson('/api/cart', [
        'product_id' => 20,
        'quantity' => 5,
    ]);
    $response->assertStatus(200);

    $this->assertDatabaseHas('Customers_Carts_T', [
        'Customers_Id' => $customer->id,
        'Products_Id' => 20,
        'Quantity' => 7,
    ]);

    // insert new with default quantity 1
    $response = $this->postJson('/api/cart', [
        'product_id' => 21,
    ]);
    $response->assertStatus(200);

    $this->assertDatabaseHas('Customers_Carts_T', [
        'Customers_Id' => $customer->id,
        'Products_Id' => 21,
        'Quantity' => 1,
    ]);
});

// 5. remove deletes a single product from cart
it('removes a single product from cart', function () {
    $user = actingCustomerUser();
    $this->actingAs($user, 'api');
    $customer = $user->customerOrCreate();

    CustomerCart::create([
        'Customers_Id' => $customer->id,
        'Products_Id' => 30,
        'Quantity' => 2,
    ]);

    $response = $this->deleteJson('/api/cart/30');
    $response->assertStatus(200);

    $this->assertDatabaseMissing('Customers_Carts_T', [
        'Customers_Id' => $customer->id,
        'Products_Id' => 30,
    ]);
});

// 6. clear empties the current customer cart and returns ok true
it('clears the cart for current customer', function () {
    $user = actingCustomerUser();
    $this->actingAs($user, 'api');
    $customer = $user->customerOrCreate();

    CustomerCart::create([
        'Customers_Id' => $customer->id,
        'Products_Id' => 40,
        'Quantity' => 1,
    ]);

    $response = $this->deleteJson('/api/cart');

    $response->assertStatus(200)
        ->assertJson(['ok' => true]);

    $this->assertDatabaseMissing('Customers_Carts_T', [
        'Customers_Id' => $customer->id,
        'Products_Id' => 40,
    ]);
});
