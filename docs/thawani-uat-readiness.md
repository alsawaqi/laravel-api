# Thawani UAT readiness

Status: prepared but disabled. No Thawani route, HTTP client, webhook, or storefront payment option is active.

## Catalogue

The live storefront audit on 25 July 2026 found four active, purchasable products. Thawani requested five to seven products, so at least one real product must be added; adding two or three would provide a better test catalogue.

Before sharing the test storefront, each product should have:

- A unique English and Arabic name
- A unique SKU
- A meaningful English and Arabic description
- A positive OMR price
- Available stock
- At least one representative image
- An active/available status

## Customer metadata

The server-side allowlist is:

| Thawani metadata key | Authoritative source |
| --- | --- |
| `customer_name` | `Customers_Master_T.Customer_Full_Name`, with an authorized contact or username fallback |
| `customer_email` | Authenticated `Secx_User_Master_T.email`, with an authorized contact fallback |
| `customer_phone` | Country code plus phone from the authorized customer/contact record |
| `customer_id` | `Customers_Master_T.Customer_Code`, with the internal customer ID as fallback |
| `order_id` | Server-generated `Orders_Placed_T.Order_Code`, with the internal order ID as fallback |

The metadata builder rejects missing name, valid email, phone, customer reference, or order reference. It exports only the five keys above and never exports passwords, tokens, addresses, loyalty balances, card data, or browser-supplied metadata.

Phone is currently optional during registration/profile setup. Before Thawani is enabled, checkout must require a valid contact phone when the selected customer record does not already have one.

## Payment invariants

- The browser is never authoritative for price, VAT, shipping, discounts, loyalty redemption, currency, or payable total.
- Laravel recalculates the order from locked database cart/product records.
- OMR amounts are converted to baisa using decimal strings, never binary floating-point multiplication.
- A browser success redirect must never mark an order paid.
- Payment can be confirmed only by an authenticated server-to-server status retrieval or a verified webhook.
- Loyalty points, stock settlement, notifications, and final order visibility must run exactly once after verified payment.
- A cancelled payment attempt must leave the customer's cart available and must not create a visible unpaid order.

## Still required from Thawani

- UAT secret key and publishable key
- Confirmed API and hosted-checkout base URLs
- Exact required metadata key names and limits
- Checkout-session request/response contract
- Success and cancellation redirect requirements
- Webhook endpoint, signature/authentication specification, retries, and event types
- Payment status values and refund/cancellation behavior
- UAT card details and acceptance scenarios

When those items arrive, implement and test the complete provider behind a disabled feature flag before exposing it in the storefront.
