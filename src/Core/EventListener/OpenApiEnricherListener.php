<?php
declare(strict_types=1);
namespace App\Core\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Post-processes /api/doc.json response to enrich tags, descriptions, and request bodies.
 * Single file — no controller changes needed.
 */
class OpenApiEnricherListener
{
    // META provides optional summaries/descriptions for key endpoints.
    // Tags are auto-detected from URL patterns — no need to list paths here just for tags.
    private const META = [
        '/api/payment/notify/{payment}' => ['tag' => 'Payment', 'summary' => ['post' => 'Payment gateway notify callback — gateway webhook'], 'desc' => ['post' => "Public payment provider webhook endpoint. The {payment} path selects the registered payment gateway (for example wechat, wallet, mock). The gateway verifies the callback signature/payload and InvoiceService applies the notify result.\nHeader: None (gateway signed; no Bearer required). Gateway sends provider-specific payload (headers + body).\nExample: POST /api/payment/notify/wechat {\"transaction_id\":\"...\",\"status\":\"paid\"} → 200 {code:0}\nSupported payment codes: mock (test), wallet (internal), wechat (WeChat Pay V3).\nErrors: 400 Invalid signature / unsupported payment; 404 Invoice not found for callback.\nIdempotent: duplicate notify for same invoice is safe."]],

        '/api/v1/manage/products' => ['summary' => ['get' => 'List products (admin)', 'post' => 'Create product (admin)'], 'desc' => ['get' => "Paginated admin product list. Supports @filter, @dql, @order, @select, @sort, @expands, @display. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/products?page=1&limit=20&@order=name|ASC\n→ {data:[{id,uuid,name,description,status,store:{uuid},isDeleted,metadata,createdAt}], paginator:{total,...}}\nFilter active: GET /api/v1/manage/products?@filter=entity.status==\"active\"\nFilter by store scopeId: GET /api/v1/manage/products?@filter=entity.store.getUuid()==\"550e8400-e29b-41d4-a716-446655440000\"\nGlobal only: GET /api/v1/manage/products?@filter=!entity.getStore()\nExpand specs: GET /api/v1/manage/products?@expands=specifications", 'post' => "Create single product or batch array. ROLE_ADMIN. Required: name. Optional: description, status in [active,inactive] (default active), metadata (object, opaque), store (UUID string or null, store scopeId; null = global product).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/products \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"iPhone 15 Pro\",\"description\":\"The latest iPhone\",\"status\":\"active\",\"store\":\"550e8400-e29b-41d4-a716-446655440000\",\"metadata\":{\"brand\":\"Apple\"}}'\n→ 201 {data:{id,uuid,name,status,store:{uuid}}}\nGlobal (no store): {\"name\":\"Generic Gift Card\",\"status\":\"active\"}\nBatch (array): POST /api/v1/manage/products [{\"name\":\"A\"},{\"name\":\"B\",\"store\":\"550e8400-e29b-41d4-a716-446655440000\"}] → 201 [{...}]\nPayload example: {\"name\":\"iPhone 15 Pro\",\"description\":\"The latest iPhone\",\"status\":\"active\",\"store\":\"550e8400-e29b-41d4-a716-446655440000\",\"metadata\":{\"brand\":\"Apple\"}}"]],
        '/api/v1/manage/products/batch-update' => ['summary' => ['post' => 'Batch update/upsert products (admin)'], 'desc' => ['post' => "Batch upsert products. Query: @mode=mixed|strict|create, @basis=id,uuid,name, @partial bool, @transform JSON.\nBody is a JSON array of product objects (each may contain id or uuid as basis).\nHeader: Authorization: Bearer <admin_jwt>\nExample mixed upsert by id:\ncurl -X POST \"/api/v1/manage/products/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"name\":\"iPhone 15 Pro Max\",\"status\":\"active\"},{\"name\":\"New Product\",\"description\":\"Fresh\",\"status\":\"active\",\"store\":\"550e8400-e29b-41d4-a716-446655440000\"}]'\n→ 200 [{id:1,...},{id:2,...}]\nStrict update only: @mode=strict (skip not-found). @partial=true continues on individual failures.\nPayload example: [{\"id\":1,\"name\":\"iPhone 15 Pro Max\"},{\"name\":\"New Product\",\"status\":\"active\"}]" ]],
        '/api/v1/manage/products/{id}' => ['summary' => ['get' => 'Get product detail (admin)', 'put' => 'Update product (admin)', 'delete' => 'Delete product (admin)'], 'desc' => ['get' => "Get single product by numeric id or uuid.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/products/1 → {data:{id,uuid,name,description,status,store:{uuid},metadata,isDeleted,createdAt}}\nSupports @select, @expands: GET /api/v1/manage/products/1?@expands=specifications,store\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/products/1", 'put' => "Update product fields: name, description, status in [active,inactive], metadata, store (UUID or null to detach). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X PUT /api/v1/manage/products/1 \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"iPhone 15 Pro Max\",\"status\":\"active\",\"metadata\":{\"brand\":\"Apple\"}}'\n→ 200 {data:{id,name}}\nDetach store (make global): {\"store\":null}. Bind to store: {\"store\":\"550e8400-e29b-41d4-a716-446655440000\"}.\nPayload example: {\"name\":\"iPhone 15 Pro Max\",\"description\":\"Updated\",\"status\":\"active\",\"store\":null,\"metadata\":{\"brand\":\"Apple\"}}", 'delete' => "Delete product by id (soft delete isDeleted=true). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/products/1 → 200 {code:0} or 204\nCurl: curl -X DELETE -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/products/1\nNote: specifications cascade via product relation (orphanRemoval)."]],
        '/api/v1/manage/products/{productId}/specifications' => ['summary' => ['get' => 'List specifications (admin)', 'post' => 'Create specification (SKU) (admin)'], 'desc' => ['get' => "Paginated spec list filtered by productId. Supports @filter, @order, @select, @expands. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/products/1/specifications?page=1&limit=20\n→ {data:[{id,uuid,name,price,status,sort,isDeleted,product:{id}}], paginator}\nFilter active: GET /api/v1/manage/products/1/specifications?@filter=entity.status==\"active\"\nSort by price: GET /api/v1/manage/products/1/specifications?@order=price|ASC\nPrice is in cents (e.g. 699900 = ¥6999.00, 1999 = ¥19.99).", 'post' => "Create specification (SKU) under product {productId}. ROLE_ADMIN. Required: name, price (int cents, >=0). Optional: status in [active,inactive] (default active), sort (int, display order).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/products/1/specifications \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"128GB Silver\",\"price\":699900,\"status\":\"active\",\"sort\":1}'\n→ 201 {data:{id,uuid,name,price,status}}\nPrice examples: 699900 = ¥6999.00, 1999 = ¥19.99, 0 = free.\nPayload example: {\"name\":\"128GB Silver\",\"price\":699900,\"status\":\"active\",\"sort\":1}"]],
        '/api/v1/manage/products/{productId}/specifications/batch-update' => ['summary' => ['post' => 'Batch update/upsert specifications (admin)'], 'desc' => ['post' => "Batch upsert specifications for product {productId}. Query: @mode=mixed|strict|create, @basis=id,uuid, @partial bool.\nBody: JSON array of spec objects (each may contain id/uuid, name, price in cents, status, sort).\nHeader: Authorization: Bearer <admin_jwt>\nExample:\ncurl -X POST \"/api/v1/manage/products/1/specifications/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":10,\"price\":799900,\"status\":\"active\"},{\"name\":\"256GB Black\",\"price\":899900,\"sort\":2}]'\n→ 200 [{...}]\nPayload example: [{\"id\":10,\"name\":\"128GB Silver V2\",\"price\":699900},{\"name\":\"256GB Black\",\"price\":899900,\"status\":\"active\",\"sort\":2}]" ]],
        '/api/v1/manage/products/{productId}/specifications/{id}' => ['summary' => ['get' => 'Get specification detail (admin)', 'put' => 'Update specification (admin)', 'delete' => 'Delete specification (admin)'], 'desc' => ['get' => "Get specification by id within product {productId}. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/products/1/specifications/10 → {data:{id,uuid,name,price,status,sort,product:{id}}}\nPrice in cents: 699900 = ¥6999.00. Supports @select.", 'put' => "Update specification: name, price in cents (>=0), status in [active,inactive], sort. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/products/1/specifications/10 -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"name\":\"256GB Deep Black\",\"price\":899900,\"status\":\"active\",\"sort\":2}' → 200 {data:{id,name,price}}\nPayload example: {\"name\":\"256GB Deep Black\",\"price\":899900,\"status\":\"active\",\"sort\":2}", 'delete' => "Delete specification by id within product {productId} (soft delete isDeleted=true). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/products/1/specifications/10 → 200 {code:0}\nCurl: curl -X DELETE -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/products/1/specifications/10"]],

        '/api/v1/manage/specifications' => ['summary' => ['get' => 'List all specifications (admin)', 'post' => 'Create specification via product ref (admin)'], 'desc' => ['get' => "Paginated admin spec list across all products. Supports @filter, @order, @select, @expands. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/specifications?page=1&limit=20&@order=price|ASC\n→ {data:[{id,uuid,name,price,status,sort,product:{id,uuid,name}}], paginator}\nFilter by price: GET /api/v1/manage/specifications?@filter=entity.price>=699900\nExpand product: GET /api/v1/manage/specifications?@expands=product\nPrice in cents (e.g. 699900 = ¥6999.00).", 'post' => "Create specification with explicit product link. ROLE_ADMIN. Required: name, product (product id int), price (int cents >=0). Optional: status in [active,inactive], sort.\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/specifications \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"128GB Silver\",\"product\":1,\"price\":699900,\"status\":\"active\",\"sort\":1}'\n→ 201 {data:{id,uuid,name,price}}\nPayload example: {\"name\":\"128GB Silver\",\"product\":1,\"price\":699900,\"status\":\"active\",\"sort\":1}"]],
        '/api/v1/manage/specifications/batch-update' => ['summary' => ['post' => 'Batch update/upsert specifications (all) (admin)'], 'desc' => ['post' => "Batch upsert specifications via global endpoint (not nested under product). Query: @mode=mixed|strict|create, @basis=id,uuid, @partial bool. Each object must include product when creating.\nHeader: Authorization: Bearer <admin_jwt>\nExample:\ncurl -X POST \"/api/v1/manage/specifications/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":10,\"price\":799900},{\"name\":\"256GB Black\",\"product\":1,\"price\":899900}]'\n→ 200 [{...}]\nPayload example: [{\"id\":10,\"price\":799900},{\"name\":\"New Spec\",\"product\":1,\"price\":899900}]" ]],
        '/api/v1/manage/specifications/{id}' => ['summary' => ['get' => 'Get specification detail (all) (admin)', 'put' => 'Update specification (all) (admin)', 'delete' => 'Delete specification (all) (admin)'], 'desc' => ['get' => "Get specification by id globally. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/specifications/10 → {data:{id,uuid,name,price,status,sort,product:{id}}}\nPrice in cents: 699900 = ¥6999.00.", 'put' => "Update spec globally: name, product (id), price in cents, status, sort. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/specifications/10 -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"name\":\"256GB Black\",\"price\":899900}' → 200\nPayload example: {\"name\":\"256GB Deep Black\",\"product\":1,\"price\":899900,\"status\":\"active\"}", 'delete' => "Delete specification globally (soft delete). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/specifications/10 → 200 {code:0}"]],

        '/api/v1/manage/orders' => ['summary' => ['get' => 'List all orders', 'post' => 'Create order (price calc)'], 'desc' => ['get' => "Paginated. Filters, sorting, expansion.\nExample: GET /api/v1/manage/orders?@filter=entity.status==\"paid\"&page=1&limit=20\nHeader: Authorization: Bearer <admin_jwt>\n→ {data:[{id,uuid,status,totalAmount}], paginator}", 'post' => "Pipeline: resolve specs → validate → compute prices → aggregate total. Amounts in cents.\nHeader: Authorization: Bearer <admin_jwt>\nExample: POST /api/v1/manage/orders\n{\n  \"items\":[{\"specificationId\":1,\"quantity\":2}],\n  \"currency\":\"CNY\",\n  \"notes\":\"gift wrap\",\n  \"metadata\":{\"receiver\":{\"name\":\"Alice\"}},\n  \"user\":1\n}\n→ 201 {data:{id,uuid,status:\"draft\",totalAmount}}\nNext: POST /manage/orders/{id}/do/submit → pending"]],
        '/api/v1/manage/orders/batch-update' => ['summary' => ['post' => 'Batch update orders'], 'desc' => ['post' => 'Batch upsert. Query: @mode=mixed|strict|create, @basis=id,uuid, @partial. Body: [{id, notes}]']],
        '/api/v1/manage/orders/{id}' => ['summary' => ['get' => 'Get order detail', 'put' => 'Update draft order', 'delete' => 'Delete draft order'], 'desc' => ['get' => "Example: GET /api/v1/manage/orders/123 → {data:{id,uuid,status,totalAmount,metadata:{_store,_completionMode}}}\nHeader: Authorization: Bearer <admin_jwt>", 'put' => "Only draft orders. Non-draft → 400.\nExample: PUT /api/v1/manage/orders/123 {\"notes\":\"updated\"} → 200", 'delete' => 'Only draft orders. Example: DELETE /api/v1/manage/orders/123 → 200']],
        '/api/v1/manage/orders/todo' => ['summary' => ['get' => 'Orders with pending actions'], 'desc' => ['get' => "Returns orders where workflow can advance. Useful for admin dashboards.\nExample: GET /api/v1/manage/orders/todo → {data:[{id,status}]}\nHeader: Authorization: Bearer <admin_jwt>"]],
        '/api/v1/manage/orders/{id}/items' => ['summary' => ['get' => 'Get order line items'], 'desc' => ['get' => "Returns items with specSnapshot/productSnapshot, unitPrice, price.\nExample: GET /api/v1/manage/orders/123/items → {data:[{specificationTitle,quantity,price}]}"]],
        '/api/v1/manage/orders/{id}/transitions' => ['summary' => ['get' => 'Available workflow transitions'], 'desc' => ['get' => "Returns [{name:\"submit\"},{name:\"cancel\"}] for current state. Use to drive do/{transition}.\nExample: GET /api/v1/manage/orders/123/transitions → {data:[{name:\"confirm\"}]}"]],
        '/api/v1/manage/orders/{id}/do/{transition}' => ['summary' => ['post' => 'Execute workflow transition'], 'desc' => ['post' => "State machine: draft→submit→pending→confirm→confirmed→pay→paid→fulfill→fulfilled→complete→completed→refund→refunded. Cancel from draft/pending/confirmed.\nWhen _completionMode=store_verification (snapshotted from Store fulfillment.requireVerification), `complete` is blocked for manual calls → 400 Store verification is required. Complete only via POST /store/{scopeId}/orders/{uuid}/verify.\nExamples:\nPOST /api/v1/manage/orders/123/do/submit  {} → pending\nPOST /api/v1/manage/orders/123/do/confirm {} → confirmed\nPOST /api/v1/manage/orders/123/do/complete {} → 400 if store_verification, else 200"]],
        '/api/v1/manage/orders/{id}/pay' => ['summary' => ['post' => 'Pay for order (wallet)'], 'desc' => ['post' => "User wallet → system wallet. Sets paidAt+paymentMethod.\nExample: POST /api/v1/manage/orders/123/pay {\"systemWalletId\":1,\"paymentMethod\":\"wallet\"} → 200\nOrder must be confirmed."]],
        '/api/v1/manage/orders/{id}/fulfill' => ['summary' => ['post' => 'Fulfill order (ship)'], 'desc' => ['post' => "Sets tracking+address+fulfilledAt. Example: POST /api/v1/manage/orders/123/fulfill {\"trackingNumber\":\"SF123456\",\"shippingAddress\":\"Beijing\"} → fulfilled\nIf StoreOrder was verified before fulfill, completion auto-fires after this transition (out-of-order handling). Order must be paid."]],
        '/api/v1/manage/orders/{id}/refund' => ['summary' => ['post' => 'Refund order (wallet)'], 'desc' => ['post' => "System wallet → user wallet. Example: POST /api/v1/manage/orders/123/refund {\"systemWalletId\":1,\"reason\":\"changed mind\"} → refunded\nOrder must be paid (current rule)."]],
        '/api/v1/manage/orders/quote' => ['summary' => ['post' => 'Quote order price (manage)'], 'desc' => ['post' => "Price preview without creating order. Pipeline: resolve specs → validate → compute prices → aggregate total.\nExample: POST /api/v1/manage/orders/quote {\"items\":[{\"specificationId\":1,\"quantity\":2}],\"currency\":\"CNY\"} → {data:{totalAmount,items}}"]],
        '/api/v1/manage/orders/{id}/payment' => ['summary' => ['post' => 'Pay order via gateway (manage)'], 'desc' => ['post' => "Creates Invoice and pays via gateway. Example: POST /api/v1/manage/orders/123/payment {\"payment\":\"mock\",\"autoPaid\":true} → {data:{invoice:{status:\"paid\"}}}\nOptions: wallet|mock|wechat, plus walletAmount/systemWalletId."]],

        '/api/v1/app/orders' => ['summary' => ['get' => 'List my orders', 'post' => 'Create order (self)'], 'desc' => ['get' => "Own orders only. Supports @filter. Example: GET /api/v1/app/orders?@filter=entity.getStatus()==\"paid\"  Header: Authorization: Bearer <user_jwt>", 'post' => "Auto-assigns current user. Supports X-Store-Code header for Store-scoped order. Stores _store+_completionMode snapshot and emits trade.order.created.v1.\nExample without store: POST /api/v1/app/orders {\"items\":[{\"specificationId\":1,\"quantity\":1}],\"notes\":\"fast\"}\n→ 201 {data:{id,uuid,status:\"draft\"}}\nExample with store: POST /api/v1/app/orders + Header X-Store-Code: XUHUI + {\"items\":[{\"specificationId\":1,\"quantity\":1}]}\n→ 201 {data:{id,uuid,status:\"pending\",metadata:{_store:{code:\"XUHUI\"},_completionMode:\"manual|store_verification\"}}}\nNext: submit→confirm→pay via /app/orders/{id}/payment"]],
        '/api/v1/app/orders/quote' => ['summary' => ['post' => 'Quote order price (app)'], 'desc' => ['post' => "Price preview. Example: POST /api/v1/app/orders/quote {\"items\":[{\"specificationId\":1,\"quantity\":1}],\"currency\":\"CNY\"} + Header X-Store-Code: XUHUI → {data:{totalAmount}}"]],
        '/api/v1/app/orders/{id}/submit' => ['summary' => ['post' => 'Submit own order'], 'desc' => ['post' => "Workflow submit: draft → pending. No body. Example: POST /api/v1/app/orders/123/submit  Header: Authorization: Bearer <user_jwt> → 200 {code:0}"]],
        '/api/v1/app/orders/{id}/confirm' => ['summary' => ['post' => 'Confirm own order'], 'desc' => ['post' => "Workflow confirm: pending → confirmed. No body. Example: POST /api/v1/app/orders/123/confirm → 200"]],
        '/api/v1/app/orders/{id}/payment' => ['summary' => ['post' => 'Pay order via gateway (app)'], 'desc' => ['post' => "Pay own order via gateway. Order must be confirmed. Creates Invoice.\nExamples:\nPOST /api/v1/app/orders/123/payment {\"payment\":\"mock\",\"autoPaid\":true} → paid immediately\nPOST /api/v1/app/orders/123/payment {\"payment\":\"wallet\",\"systemWalletId\":99} → wallet debit\nPOST /api/v1/app/orders/123/payment {\"payment\":\"wechat\",\"tradeType\":\"jsapi\"} → {payload:{timeStamp,nonceStr,package,signType,paySign}} for wx.requestPayment"]],
        '/api/v1/app/specifications/by-product/{productId}' => ['summary' => ['get' => 'List specifications by product (public)'], 'desc' => ['get' => "Returns active specifications for given product {productId}, respecting X-Store-Code scoping. Product must be active/non-deleted and visible in current store scope (global or matching store). No auth.\nExamples:\nGET /api/v1/app/specifications/by-product/1 → {data:[{id,uuid,name,price,status,sort,product:{id}}]}\nWith store: GET /api/v1/app/specifications/by-product/1 + Header X-Store-Code: XUHUI\nCurl: curl /api/v1/app/specifications/by-product/1\n→ [] if product not visible in scope (store mismatch or inactive)\nPrice in cents: 699900 = ¥6999.00"]],
        '/api/v1/app/orders/{id}' => ['summary' => ['get' => 'Get order detail (own)'], 'desc' => ['get' => '404 if not authenticated user\'s order.']],
        '/api/v1/app/orders/{id}/items' => ['summary' => ['get' => 'Get order items (own)']],
        '/api/v1/app/orders/{id}/cancel' => ['summary' => ['post' => 'Cancel own order'], 'desc' => ['post' => 'Allowed: draft, pending, confirmed. Not paid+.']],
        '/api/v1/app/products' => ['summary' => ['get' => 'List active products (public)'], 'desc' => ['get' => "Public active-only product list. commonFilter {status:active, isDeleted:false, store:null|scoped} applied server-side. Without X-Store-Code header returns global products only; with X-Store-Code: <storeCode> merges global + that store's active products. No auth required but JWT accepted.\nPaginated + same query DSL (@filter,@order,@select,@expands) but limited to active/non-deleted.\nExamples:\ncurl /api/v1/app/products?page=1&limit=20 → {data:[{id,uuid,name,description,status,metadata}], paginator}\nWith store scope: curl -H \"X-Store-Code: XUHUI\" /api/v1/app/products → includes XUHUI store products + globals\nFiltered: GET /api/v1/app/products?@filter=entity.name==\"iPhone\"\nSort: GET /api/v1/app/products?@order=name|ASC\nExpand: GET /api/v1/app/products?@expands=specifications\nStore scopeId is resolved via X-Store-Code header (store code → uuid), not a query param."]],
        '/api/v1/app/products/{id}' => ['summary' => ['get' => 'Get product detail (public)'], 'desc' => ['get' => "Public product detail by id. Returns active, non-deleted product; respects X-Store-Code scoping (global or matching store). No auth.\nExample: GET /api/v1/app/products/1 → {data:{id,uuid,name,description,status,store:{uuid},metadata,createdAt}}\nWith store: GET /api/v1/app/products/1 + Header X-Store-Code: XUHUI → 200 if product belongs to XUHUI or is global, else filtered/empty\nCurl: curl /api/v1/app/products/1\n→ 200 or 404 if not found / not active"]],

        '/api/v1/manage/categories' => ['summary' => ['get' => 'List categories (admin)', 'post' => 'Create category (admin)'], 'desc' => ['get' => "Paginated admin category list. Supports @filter, @dql, @order, @select, @sort, @expands, @display. ROLE_ADMIN. Hierarchical via parent self-FK.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/categories?page=1&limit=20&@order=sortOrder|ASC → {data:[{id,name,slug,description,parent:{id},sortOrder,enabled}], paginator:{total,...}}\nFilter enabled: GET /api/v1/manage/categories?@filter=entity.enabled==true\nFilter by parent null (roots): GET /api/v1/manage/categories?@filter=entity.parent==null\nFilter by slug: GET /api/v1/manage/categories?@filter=entity.slug==\"news\"\n@select sparse: GET /api/v1/manage/categories?@select=id,name,slug\nExpand parent: GET /api/v1/manage/categories?@expands=parent", 'post' => "Create single category or batch array. ROLE_ADMIN. Required: name, slug. Optional: description (text nullable), parent (int id nullable, self-FK SET NULL), sortOrder (int, display order default 0), enabled (bool default true).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/categories \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"News\",\"slug\":\"news\",\"description\":\"EDC news and updates\",\"parent\":null,\"sortOrder\":1,\"enabled\":true}'\n→ 201 {data:{id,name,slug,sortOrder,enabled}}\nChild category: {\"name\":\"Tech News\",\"slug\":\"tech-news\",\"parent\":1,\"sortOrder\":2,\"enabled\":true}\nBatch (array): POST /api/v1/manage/categories [{\"name\":\"A\",\"slug\":\"a\"},{\"name\":\"B\",\"slug\":\"b\",\"parent\":1}] → 201 [{...}]\nPayload example: {\"name\":\"News\",\"slug\":\"news\",\"description\":\"EDC news\",\"parent\":null,\"sortOrder\":1,\"enabled\":true}\nErrors: 400 Missing name/slug / invalid slug / duplicate slug → 409; 401/403 auth."]],
        '/api/v1/manage/categories/batch-update' => ['summary' => ['post' => 'Batch update/upsert categories (admin)'], 'desc' => ['post' => "Batch upsert categories. Query: @mode=mixed|strict|create, @basis=id,slug, @partial bool.\nBody: JSON array of category objects (each may contain id or slug as match key).\nHeader: Authorization: Bearer <admin_jwt>\nExample:\ncurl -X POST \"/api/v1/manage/categories/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"name\":\"News V2\",\"slug\":\"news-v2\",\"enabled\":true},{\"name\":\"New Category\",\"slug\":\"new-cat\",\"sortOrder\":5}]'\n→ 200 [{...}]\nSlug basis: @basis=slug. @partial=true continues on individual failures.\nPayload example: [{\"id\":1,\"name\":\"News V2\"},{\"name\":\"New Category\",\"slug\":\"new-cat\"}]\nErrors: 400 Invalid basis; 404 in strict mode skipped."]],
        '/api/v1/manage/categories/{id}' => ['summary' => ['get' => 'Get category detail (admin)', 'put' => 'Update category (admin)', 'delete' => 'Delete category (admin)'], 'desc' => ['get' => "Get category by id. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/categories/1 → {data:{id,name,slug,description,parent:{id,name},sortOrder,enabled,createdAt}}\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/categories/1\nSupports @select, @expands=parent.\nErrors: 401/403; 404 Not found.", 'put' => "Update category: name, slug, description, parent (int|null to move/re-parent), sortOrder, enabled. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/categories/1 -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"name\":\"News Updated\",\"slug\":\"news-updated\",\"sortOrder\":2,\"enabled\":false}' → 200 {data:{id,name}}\nDetach parent (make root): {\"parent\":null}. Re-parent: {\"parent\":2}.\nPayload example: {\"name\":\"News Updated\",\"slug\":\"news-updated\",\"description\":\"Updated\",\"parent\":null,\"sortOrder\":2,\"enabled\":true}\nErrors: 400 Duplicate slug → 409; 404 Not found.", 'delete' => "Delete category. ROLE_ADMIN. Parent SET NULL: children become roots.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/categories/1 → 200 {code:0}\nCurl: curl -X DELETE -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/categories/1"]],
        '/api/v1/manage/tags' => ['summary' => ['get' => 'List tags (admin)', 'post' => 'Create tag (admin)'], 'desc' => ['get' => "Paginated admin tag list. Supports @filter, @dql, @order, @select, @sort, @expands. ROLE_ADMIN. Flat (no hierarchy).\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/tags?page=1&limit=20&@order=name|ASC → {data:[{id,name,slug,color}], paginator}\nFilter by color: GET /api/v1/manage/tags?@filter=entity.color==\"#ff0000\"\nFilter by slug: GET /api/v1/manage/tags?@filter=entity.slug==\"featured\"\n@select: GET /api/v1/manage/tags?@select=id,name,slug,color", 'post' => "Create single tag or batch array. ROLE_ADMIN. Required: name, slug. Optional: color (string hex nullable, e.g. \"#ff6600\" or null).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/tags \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"Featured\",\"slug\":\"featured\",\"color\":\"#ff6600\"}'\n→ 201 {data:{id,name,slug,color}}\nWithout color: {\"name\":\"Hot\",\"slug\":\"hot\"}\nBatch: POST /api/v1/manage/tags [{\"name\":\"A\",\"slug\":\"a\",\"color\":\"#000\"}] → 201 [{...}]\nPayload example: {\"name\":\"Featured\",\"slug\":\"featured\",\"color\":\"#ff6600\"}\nErrors: 400 Missing name/slug / duplicate slug → 409."]],
        '/api/v1/manage/tags/batch-update' => ['summary' => ['post' => 'Batch update/upsert tags (admin)'], 'desc' => ['post' => "Batch upsert tags. Query: @mode=mixed|strict|create, @basis=id,slug, @partial bool.\nBody: JSON array of tag objects (each may contain id or slug).\nHeader: Authorization: Bearer <admin_jwt>\nExample:\ncurl -X POST \"/api/v1/manage/tags/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"name\":\"Featured V2\",\"color\":\"#00ff00\"},{\"name\":\"New Tag\",\"slug\":\"new-tag\",\"color\":\"#0000ff\"}]'\n→ 200 [{...}]\nPayload example: [{\"id\":1,\"name\":\"Featured V2\"},{\"name\":\"New Tag\",\"slug\":\"new-tag\"}]"]],
        '/api/v1/manage/tags/{id}' => ['summary' => ['get' => 'Get tag detail (admin)', 'put' => 'Update tag (admin)', 'delete' => 'Delete tag (admin)'], 'desc' => ['get' => "Get tag by id. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/tags/1 → {data:{id,name,slug,color,createdAt}}\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/tags/1\nSupports @select.", 'put' => "Update tag: name, slug, color (string hex or null). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/tags/1 -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"name\":\"Featured V2\",\"color\":\"#00ff00\"}' → 200\nClear color: {\"color\":null}.\nPayload example: {\"name\":\"Featured V2\",\"slug\":\"featured-v2\",\"color\":\"#00ff00\"}", 'delete' => "Delete tag. ROLE_ADMIN. Join table common_content_tag rows removed.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/tags/1 → 200 {code:0}\nCurl: curl -X DELETE -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/tags/1"]],
        '/api/v1/manage/contents' => ['summary' => ['get' => 'List contents (admin)', 'post' => 'Create content (admin)'], 'desc' => ['get' => "Paginated admin list. Supports @filter, @dql, @order, @select, @sort, @expands, @display. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/contents?page=1&limit=20&@order=createdAt|DESC → {data:[{id,title,body,category:{id,name},tags:[{id,name}],metadata}], paginator:{total,...}}\nFilter by title: GET /api/v1/manage/contents?@filter=entity.title==\"Hello\"\nFilter by category: GET /api/v1/manage/contents?@filter=entity.category==1\nExpand category/tags: GET /api/v1/manage/contents?@expands=category,tags\nDQL: GET /api/v1/manage/contents?@dql=SELECT c FROM App\\Common\\Entity\\Content c WHERE c.title LIKE '%news%'\n@select sparse: GET /api/v1/manage/contents?@select=id,title\n@display field projection also supported.", 'post' => "Create single content or batch array. ROLE_ADMIN. Required: title (string). Optional: body (text/markdown, nullable), category (int id | null, FK to common_category, SET NULL on delete), tags (int[] tag ids, ManyToMany via common_content_tag), metadata (object nullable, json).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/contents \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"title\":\"EDC News: New Store Opening\",\"body\":\"We are opening a new store in Xuhui...\",\"category\":1,\"tags\":[1,2],\"metadata\":{\"source\":\"editorial\",\"featured\":true}}'\n→ 201 {data:{id,title,body,category:{id},tags:[{id}],metadata}}\nBatch (array): POST /api/v1/manage/contents [{\"title\":\"Article A\",\"body\":\"Body A\",\"category\":1,\"tags\":[1]},{\"title\":\"Article B\",\"body\":\"Body B\"}] → 201 [{...},{...}]\nSingle example payload: {\"title\":\"EDC News: New Store Opening\",\"body\":\"We are opening a new store in Xuhui...\",\"category\":1,\"tags\":[1,2],\"metadata\":{\"source\":\"editorial\",\"featured\":true}}"]],
        '/api/v1/manage/contents/batch-update' => ['summary' => ['post' => 'Batch update/upsert contents (admin)'], 'desc' => ['post' => "Batch upsert contents. Query: @mode=mixed|strict|create, @basis=id, @partial bool, @transform JSON.\nBody is a JSON array of content objects (each may contain id as basis).\nHeader: Authorization: Bearer <admin_jwt>\nExample mixed upsert by id:\ncurl -X POST \"/api/v1/manage/contents/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"title\":\"Updated Title\",\"body\":\"Updated body\",\"category\":2,\"tags\":[2]},{\"title\":\"New Article\",\"body\":\"Fresh body\",\"category\":1,\"tags\":[1]}]'\n→ 200 [{id:1, ...}, {id:2, ...}]\nStrict update only: @mode=strict (skip not-found). @partial=true continues on individual failures.\nPayload example: [{\"id\":1,\"title\":\"Updated Title\",\"body\":\"Updated body\",\"category\":2,\"tags\":[2]},{\"title\":\"New Article\",\"body\":\"Fresh body\",\"category\":1,\"tags\":[1]}]"]],
        '/api/v1/manage/contents/{id}' => ['summary' => ['get' => 'Get content detail (admin)', 'put' => 'Update content (admin)', 'delete' => 'Delete content (admin)'], 'desc' => ['get' => "Get single content by numeric id. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/contents/1 → {data:{id,title,body,category:{id,name},tags:[{id,name,slug,color}],metadata,createdAt,updatedAt}}\nSupports @select, @expands: GET /api/v1/manage/contents/1?@expands=category,tags\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/contents/1", 'put' => "Update content fields: title, body, category (int|null), tags (int[]), metadata (object|null). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X PUT /api/v1/manage/contents/1 \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"title\":\"Updated Title\",\"body\":\"Revised body with #markdown\",\"category\":2,\"tags\":[2,3],\"metadata\":{\"featured\":false}}'\n→ 200 {data:{id,title}}\nDetach category: {\"category\":null}. Replace tags: {\"tags\":[1]}. Clear metadata: {\"metadata\":null}.\nPayload example: {\"title\":\"Updated Title\",\"body\":\"Revised body\",\"category\":2,\"tags\":[2,3],\"metadata\":{\"featured\":false}}", 'delete' => "Delete content by id. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/contents/1 → 200 {code:0}\nCurl: curl -X DELETE -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/contents/1\nJoin table common_content_tag rows cascade-deleted."]],
        '/api/v1/manage/comments' => ['summary' => ['get' => 'List comments (admin)', 'post' => 'Create comment (admin)'], 'desc' => ['get' => "Paginated admin comment list. Supports @filter, @dql, @order, @select, @sort, @expands, @display. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/comments?page=1&limit=20&@order=createdAt|DESC → {data:[{id,body,entityType,entityId,authorName,authorEmail,status,parent:{id}}], paginator:{total,...}}\nFilter by status: GET /api/v1/manage/comments?@filter=entity.status==\"pending\"\nFilter by entity: GET /api/v1/manage/comments?@filter=entity.entityType==\"App\\\\Common\\\\Entity\\\\Content\"&@filter=entity.entityId==1\nExpand parent/author: GET /api/v1/manage/comments?@expands=parent,author\n@select sparse: GET /api/v1/manage/comments?@select=id,body,status", 'post' => "Create single comment or batch array. ROLE_ADMIN. Required: body (text), entityType (string FQCN e.g. \"App\\\\Common\\\\Entity\\\\Content\" or \"App\\\\Common\\\\Entity\\\\Page\"), entityId (int).\nOptional: authorName (string nullable), authorEmail (string nullable), author (int user id nullable, FK SET NULL), parent (int comment id nullable, self-FK CASCADE for replies), status (string enum [pending,approved,rejected,spam], default pending).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/comments \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"body\":\"Great article! Thanks for sharing.\",\"entityType\":\"App\\\\Common\\\\Entity\\\\Content\",\"entityId\":1,\"authorName\":\"Alice\",\"authorEmail\":\"alice@example.com\",\"status\":\"approved\",\"parent\":null}'\n→ 201 {data:{id,body,status:\"approved\"}}\nThreaded reply: POST /api/v1/manage/comments {\"body\":\"Reply to #1\",\"entityType\":\"App\\\\Common\\\\Entity\\\\Content\",\"entityId\":1,\"parent\":1} → 201\nPayload example: {\"body\":\"Great article! Thanks for sharing.\",\"entityType\":\"App\\\\Common\\\\Entity\\\\Content\",\"entityId\":1,\"authorName\":\"Alice\",\"authorEmail\":\"alice@example.com\",\"status\":\"approved\",\"parent\":null}"]],
        '/api/v1/manage/comments/batch-update' => ['summary' => ['post' => 'Batch update/upsert comments (admin)'], 'desc' => ['post' => "Batch upsert comments. Query: @mode=mixed|strict|create, @basis=id, @partial bool.\nBody: JSON array of comment objects (each may contain id as basis).\nHeader: Authorization: Bearer <admin_jwt>\nExample:\ncurl -X POST \"/api/v1/manage/comments/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"body\":\"Updated body\",\"status\":\"approved\"},{\"body\":\"New comment\",\"entityType\":\"App\\\\Common\\\\Entity\\\\Page\",\"entityId\":2}]'\n→ 200 [{...}, {...}]\nStrict: @mode=strict. @partial=true continues on individual failures.\nPayload example: [{\"id\":1,\"body\":\"Updated body\",\"status\":\"approved\"},{\"body\":\"New comment\",\"entityType\":\"App\\\\Common\\\\Entity\\\\Page\",\"entityId\":2}]"]],
        '/api/v1/manage/comments/{id}' => ['summary' => ['get' => 'Get comment detail (admin)', 'put' => 'Update comment (admin)', 'delete' => 'Delete comment (admin)'], 'desc' => ['get' => "Get comment by id. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/comments/1 → {data:{id,body,entityType,entityId,authorName,authorEmail,author:{id},parent:{id},status,createdAt}}\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/comments/1\nSupports @select, @expands=parent,author.", 'put' => "Update comment: body, authorName, authorEmail, status. Note: entityType/entityId/parent/author not updatable via this endpoint (acceptedUpdateProperties: body,authorName,authorEmail,status). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/comments/1 -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"body\":\"Updated comment body\",\"status\":\"approved\"}' → 200 {data:{id,body,status}}\nModeration: {\"status\":\"approved\"} | {\"status\":\"rejected\"} | {\"status\":\"spam\"}\nPayload example: {\"body\":\"Updated comment body\",\"authorName\":\"Alice\",\"authorEmail\":\"alice@example.com\",\"status\":\"approved\"}", 'delete' => "Delete comment. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/comments/1 → 200 {code:0}\nCurl: curl -X DELETE -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/comments/1\nChild replies cascade-deleted (onDelete CASCADE on parent_id)."]],
        '/api/v1/manage/pages' => ['summary' => ['get' => 'List pages (admin)', 'post' => 'Create page (admin)'], 'desc' => ['get' => "Paginated admin page list. Supports @filter, @dql, @order, @select, @sort, @expands, @display. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/pages?page=1&limit=20&@order=createdAt|DESC → {data:[{id,title,slug,body,metaTitle,metaDescription,status,publishedAt}], paginator:{total,...}}\nFilter by status: GET /api/v1/manage/pages?@filter=entity.status==\"published\"\nFilter by slug: GET /api/v1/manage/pages?@filter=entity.slug==\"about-us\"\n@select: GET /api/v1/manage/pages?@select=id,title,slug,status", 'post' => "Create single page or batch array. ROLE_ADMIN. Required: title (string), slug (string unique [a-z0-9_-], e.g. \"about-us\").\nOptional: body (text nullable, markdown/html), metaTitle (string nullable, SEO), metaDescription (text nullable, SEO), status (string enum [draft,published,archived], default draft), publishedAt (datetime ISO8601 nullable, e.g. \"2026-09-04T08:00:00+00:00\").\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/pages \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"title\":\"About Us\",\"slug\":\"about-us\",\"body\":\"# About EDC\\nWe are a community...\",\"metaTitle\":\"About EDC - Community\",\"metaDescription\":\"Learn about EDC community\",\"status\":\"published\",\"publishedAt\":\"2026-09-04T08:00:00+00:00\"}'\n→ 201 {data:{id,title,slug,status}}\nBatch: POST /api/v1/manage/pages [{\"title\":\"FAQ\",\"slug\":\"faq\",\"status\":\"draft\"}] → 201 [{...}]\nPayload example: {\"title\":\"About Us\",\"slug\":\"about-us\",\"body\":\"# About EDC\\nWe are...\",\"metaTitle\":\"About EDC\",\"metaDescription\":\"Learn about EDC\",\"status\":\"published\",\"publishedAt\":\"2026-09-04T08:00:00+00:00\"}"]],
        '/api/v1/manage/pages/batch-update' => ['summary' => ['post' => 'Batch update/upsert pages (admin)'], 'desc' => ['post' => "Batch upsert pages. Query: @mode=mixed|strict|create, @basis=id,slug, @partial bool.\nBody: JSON array of page objects (each may contain id or slug as match key).\nHeader: Authorization: Bearer <admin_jwt>\nExample:\ncurl -X POST \"/api/v1/manage/pages/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"title\":\"About Us V2\",\"status\":\"published\"},{\"title\":\"New Page\",\"slug\":\"new-page\",\"body\":\"Hello\"}]'\n→ 200 [{...}, {...}]\nSlug basis: @basis=slug. @partial=true continues on failures.\nPayload example: [{\"id\":1,\"title\":\"About Us V2\",\"status\":\"published\"},{\"title\":\"New Page\",\"slug\":\"new-page\",\"status\":\"draft\"}]"]],
        '/api/v1/manage/pages/{id}' => ['summary' => ['get' => 'Get page detail (admin)', 'put' => 'Update page (admin)', 'delete' => 'Delete page (admin)'], 'desc' => ['get' => "Get page by id. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/pages/1 → {data:{id,title,slug,body,metaTitle,metaDescription,status,publishedAt,createdAt,updatedAt}}\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/pages/1\nSupports @select, @expands.", 'put' => "Update page: title, slug, body, metaTitle, metaDescription, status, publishedAt. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/pages/1 -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"title\":\"About Us V2\",\"slug\":\"about-us-v2\",\"status\":\"published\",\"publishedAt\":\"2026-09-04T10:00:00+00:00\"}' → 200 {data:{id,title}}\nPublish: {\"status\":\"published\",\"publishedAt\":\"2026-09-04T08:00:00+00:00\"}. Archive: {\"status\":\"archived\"}.\nPayload example: {\"title\":\"About Us V2\",\"slug\":\"about-us-v2\",\"body\":\"Updated body\",\"metaTitle\":\"Updated SEO\",\"metaDescription\":\"Updated desc\",\"status\":\"published\",\"publishedAt\":\"2026-09-04T10:00:00+00:00\"}", 'delete' => "Delete page. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/pages/1 → 200 {code:0}\nCurl: curl -X DELETE -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/pages/1"]],
        '/api/v1/manage/media' => ['summary' => ['get' => 'List media (admin)', 'post' => 'Create media metadata (admin)'], 'desc' => ['get' => "Paginated admin media list (all users, user global filter disabled). Supports @filter, @dql, @order, @select, @sort, @expands, @display. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/media?page=1&limit=20&@order=createdAt|DESC → {data:[{id,filename,originalFilename,mimeType,size,path,storage,alt,title,width,height,category:{id},user:{id}}], paginator}\nFilter by mime: GET /api/v1/manage/media?@filter=entity.mimeType==\"image/jpeg\"\nFilter by storage: GET /api/v1/manage/media?@filter=entity.storage==\"local\"\nApp endpoint is user-scoped (commonFilter user==current); manage is not.", 'post' => "Create media metadata directly (without file upload) or batch array. ROLE_ADMIN. Required: filename, originalFilename, mimeType, size (int bytes), path (string URL/path). Optional: storage in [local,qiniu] (default local), user (int user id nullable), category (int category id nullable), alt, title, width, height. For file upload use POST /manage/media/upload (multipart).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/media \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"filename\":\"abc123.jpg\",\"originalFilename\":\"photo.jpg\",\"mimeType\":\"image/jpeg\",\"size\":123456,\"path\":\"/uploads/2026/09/abc123.jpg\",\"storage\":\"local\",\"category\":1,\"alt\":\"EDC photo\",\"title\":\"Photo\"}'\n→ 201 {data:{id,path}}\nBatch: POST /api/v1/manage/media [{\"filename\":\"a.jpg\",...}] → 201 [{...}]\nPayload example: {\"filename\":\"abc123.jpg\",\"originalFilename\":\"photo.jpg\",\"mimeType\":\"image/jpeg\",\"size\":123456,\"path\":\"/uploads/2026/09/abc123.jpg\"}"]],
        '/api/v1/manage/media/upload' => ['summary' => ['post' => 'Upload media file (admin, multipart)'], 'desc' => ['post' => "Admin multipart upload endpoint (all users visible, not user-scoped). Send binary file in form field `file`. Reuses same storage flow as App endpoint.\nHeader: Authorization: Bearer <admin_jwt> (multipart, no JSON)\nForm fields: file (binary, required), storage=local|qiniu (optional, default media.storage.default), category (int category id), alt, title, width, height.\nLocal uploads stored under public/uploads/{YYYYMM}/ and return root-relative /uploads/... URL. Qiniu requires qiniu.* settings + qiniu/php-sdk.\nCurl:\ncurl -X POST /api/v1/manage/media/upload \\\n  -H \"Authorization: Bearer <admin_jwt>\" \\\n  -F file=@/tmp/photo.jpg -F storage=local -F category=1 -F alt=\"EDC\" -F title=\"Photo\"\n→ 201 {data:{id,filename,path:\"/uploads/2026/09/abc.jpg\",storage:\"local\"}}\nErrors: 400 No file / invalid mime (allowlist: image/jpeg,image/png,image/gif,image/webp,application/pdf) / max 10MB / missing storage driver; 401/403 auth."]],
        '/api/v1/manage/media/batch-update' => ['summary' => ['post' => 'Batch update/upsert media (admin)'], 'desc' => ['post' => "Batch upsert media. Query: @mode=mixed|strict|create, @basis=id,uuid, @partial bool.\nBody: JSON array of media objects (each may contain id or uuid).\nHeader: Authorization: Bearer <admin_jwt>\nExample:\ncurl -X POST \"/api/v1/manage/media/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"alt\":\"Updated alt\",\"title\":\"New title\"},{\"filename\":\"new.jpg\",\"originalFilename\":\"new.jpg\",\"mimeType\":\"image/jpeg\",\"size\":100,\"path\":\"/uploads/new.jpg\"}]'\n→ 200\nPayload example: [{\"id\":1,\"alt\":\"Updated\"}]"]],
        '/api/v1/manage/media/{id}' => ['summary' => ['get' => 'Get media detail (admin)', 'put' => 'Update media (admin)', 'delete' => 'Delete media (admin)'], 'desc' => ['get' => "Get media by id (any user) — admin not user-scoped. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/media/1 → {data:{id,filename,path,storage,mimeType,size,alt,title,width,height,user:{id},category:{id}}}\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/media/1\nSupports @select.", 'put' => "Update media metadata: filename, originalFilename, mimeType, size, path, storage, user (int|null), category (int|null), alt, title, width, height. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/media/1 -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"alt\":\"New alt\",\"title\":\"New title\"}' → 200\nPayload example: {\"alt\":\"New alt\",\"title\":\"New\"}", 'delete' => "Delete media. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/media/1 → 200 {code:0}\nCurl: curl -X DELETE -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/media/1"]],
        '/api/v1/manage/settings' => ['summary' => ['get' => 'List settings (admin)', 'post' => 'Create setting (admin)'], 'desc' => ['get' => "Paginated admin settings list. Supports @filter, @dql, @order, @select, @sort. ROLE_ADMIN. Key-value store.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/settings?page=1&limit=20 → {data:[{id,key,value,type,groupName,label,description,sortOrder}], paginator}\nFilter by key: GET /api/v1/manage/settings?@filter=entity.key==\"site.name\"\nFilter by group: GET /api/v1/manage/settings?@filter=entity.groupName==\"site\"\n@order: ?@order=sortOrder|ASC", 'post' => "Create single setting or batch array. ROLE_ADMIN. Required: key (string unique, e.g. \"site.name\" or \"media.storage.default\"). Optional: value (string, stored as text/json depending on type), type (string enum e.g. string|int|bool|json, default string), groupName (string nullable, grouping), label (string nullable, human-readable), description (text nullable), sortOrder (int default 0).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/settings \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"key\":\"site.name\",\"value\":\"EDC Online\",\"type\":\"string\",\"groupName\":\"site\",\"label\":\"Site Name\",\"sortOrder\":1}'\n→ 201 {data:{id,key,value}}\nPayload example: {\"key\":\"site.name\",\"value\":\"EDC Online\",\"type\":\"string\",\"groupName\":\"site\"}\nErrors: 400 Missing key / duplicate key → 409."]],
        '/api/v1/manage/settings/batch-update' => ['summary' => ['post' => 'Batch update/upsert settings (admin)'], 'desc' => ['post' => "Batch upsert settings. Query: @mode=mixed|strict|create, @basis=id,key, @partial bool.\nBody: JSON array of setting objects (each may contain id or key).\nHeader: Authorization: Bearer <admin_jwt>\nExample:\ncurl -X POST \"/api/v1/manage/settings/batch-update?@mode=mixed&@basis=key\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"key\":\"site.name\",\"value\":\"EDC V2\"},{\"key\":\"new.key\",\"value\":\"val\",\"type\":\"string\"}]'\n→ 200"]],
        '/api/v1/manage/settings/{id}' => ['summary' => ['get' => 'Get setting detail (admin)', 'put' => 'Update setting (admin)', 'delete' => 'Delete setting (admin)'], 'desc' => ['get' => "Get setting by id. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/settings/1 → {data:{id,key,value,type,groupName,label,description,sortOrder}}", 'put' => "Update setting: value, type, groupName, label, description, sortOrder (key is updatable but uniqueness enforced). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/settings/1 -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"value\":\"EDC V2\",\"label\":\"Site Name V2\"}' → 200\nPayload example: {\"value\":\"EDC V2\",\"label\":\"Updated\"}", 'delete' => "Delete setting. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/settings/1 → 200 {code:0}"]],

        '/api/v1/app/categories' => ['summary' => ['get' => 'List enabled categories (public)'], 'desc' => ['get' => "Public list — commonFilter {enabled:true} applied server-side. No auth required but JWT accepted. Paginated + same DSL (@filter,@order,@select,@expands) but limited to enabled.\nExample: GET /api/v1/app/categories?page=1&limit=20&@order=sortOrder|ASC → {data:[{id,name,slug,description,sortOrder,enabled,parent:{id}}], paginator}\nFilter by slug: GET /api/v1/app/categories?@filter=entity.slug==\"news\"\nCurl: curl \"/api/v1/app/categories?page=1&limit=20\" → {data:[...]}\nOnly enabled categories returned; disabled hidden even if requested via @filter."]],
        '/api/v1/app/categories/{id}' => ['summary' => ['get' => 'Get category detail (public)'], 'desc' => ['get' => "Public category detail by id. No auth. Returns enabled category; disabled returns 404 via commonFilter.\nExample: GET /api/v1/app/categories/1 → {data:{id,name,slug,description,parent:{id},sortOrder,enabled}}\nCurl: curl /api/v1/app/categories/1 → 200 or 404"]],
        '/api/v1/app/tags' => ['summary' => ['get' => 'List tags (public)'], 'desc' => ['get' => "Public tag list. No auth required. Paginated.\nExample: GET /api/v1/app/tags?page=1&limit=20&@order=name|ASC → {data:[{id,name,slug,color}], paginator}\nCurl: curl /api/v1/app/tags → {data:[...]}"]],
        '/api/v1/app/tags/{id}' => ['summary' => ['get' => 'Get tag detail (public)'], 'desc' => ['get' => "Public tag detail by id. No auth.\nExample: GET /api/v1/app/tags/1 → {data:{id,name,slug,color}}\nCurl: curl /api/v1/app/tags/1 → 200 or 404"]],
        '/api/v1/app/contents' => ['summary' => ['get' => 'List contents (app)'], 'desc' => ['get' => "App-facing content list. Paginated. Supports @filter, @order, @select, @sort, @expands, @display. No ROLE_ADMIN required — JWT optional but list requires authentication per current guard (Bearer <user_jwt>). Mirrors manage schema but filtered for app consumers.\nExample: GET /api/v1/app/contents?page=1&limit=20&@order=createdAt|DESC → {data:[{id,title,body,category:{id},tags:[{id}],metadata}], paginator}\nFilter by category: GET /api/v1/app/contents?@filter=entity.category==1\nFilter by title: GET /api/v1/app/contents?@filter=entity.title==\"EDC\"\nExpand category/tags: GET /api/v1/app/contents?@expands=category,tags\nHeader: Authorization: Bearer <user_jwt>\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" \"/api/v1/app/contents?page=1&limit=20\"\n→ {data:[{id,title,body}], paginator}"]],
        '/api/v1/app/contents/{id}' => ['summary' => ['get' => 'Get content detail (app)'], 'desc' => ['get' => "Get single content by id (app). No ROLE_ADMIN required but JWT required per guard. Supports @select, @expands=category,tags.\nExample: GET /api/v1/app/contents/1 → {data:{id,title,body,category:{id,name},tags:[{id,name,slug,color}],metadata,createdAt}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/contents/1 → 200 or 404\nWith expands: GET /api/v1/app/contents/1?@expands=category,tags"]],
        '/api/v1/app/comments' => ['summary' => ['get' => 'List my comments (app)', 'post' => 'Create comment (app, pending)'], 'desc' => ['get' => "List own comments only (commonFilter: author == current user). Paginated. Supports @filter, @order. Requires JWT (author-scoped).\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/comments?page=1&limit=20&@order=createdAt|DESC → {data:[{id,body,entityType,entityId,status,parent:{id}}], paginator}\nFilter by entity: GET /api/v1/app/comments?@filter=entity.entityType==\"App\\\\Common\\\\Entity\\\\Content\"&@filter=entity.entityId==1\nClient can also @filter status pending/approved.\n→ {data:[...]} filtered to own author.", 'post' => "Create comment as authenticated user. Required: body (text), entityType (string FQCN, e.g. \"App\\\\Common\\\\Entity\\\\Content\", \"App\\\\Common\\\\Entity\\\\Page\"), entityId (int).\nOptional: parent (int comment id for threaded replies). Ignored/server-set: author, authorName, authorEmail, status (always pending, input author/status fields ignored). commonFilter ensures author binding.\nHeader: Authorization: Bearer <user_jwt>\nCurl (top-level):\ncurl -X POST /api/v1/app/comments \\\n  -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"body\":\"Great article!\",\"entityType\":\"App\\\\Common\\\\Entity\\\\Content\",\"entityId\":1}'\n→ 201 {data:{id,body,status:\"pending\",authorName,authorEmail}}\nReply example (parent=1):\ncurl -X POST /api/v1/app/comments -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" -d '{\"body\":\"Thanks!\",\"entityType\":\"App\\\\Common\\\\Entity\\\\Content\",\"entityId\":1,\"parent\":1}' → 201\nPayload example: {\"body\":\"Great article!\",\"entityType\":\"App\\\\Common\\\\Entity\\\\Content\",\"entityId\":1,\"parent\":null}\nNew comments default to pending — moderation via manage/comments/{id} (status approved/rejected)."]],
        '/api/v1/app/comments/{id}' => ['summary' => ['get' => 'Get comment detail (app)'], 'desc' => ['get' => "Get own comment detail by id. Requires JWT; commonFilter author == current user means 404 if not own. Supports @select, @expands=parent,author.\nExample: GET /api/v1/app/comments/1 → {data:{id,body,entityType,entityId,authorName,authorEmail,status,parent:{id},createdAt}}\nHeader: Authorization: Bearer <user_jwt>\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/comments/1 → 200 or 404\nApp list is author-scoped; manage/comments/{id} returns any comment (admin)."]],
        '/api/v1/app/pages' => ['summary' => ['get' => 'List published pages (public)'], 'desc' => ['get' => "Public list — commonFilter {status:\"published\"} applied server-side. Draft/archived hidden even if requested via @filter. No auth required but JWT accepted. Paginated + same DSL (@filter,@order,@select,@expands) but limited to published.\nExample: GET /api/v1/app/pages?page=1&limit=20&@order=publishedAt|DESC → {data:[{id,title,slug,body,metaTitle,publishedAt}], paginator}\nFilter by slug: GET /api/v1/app/pages?@filter=entity.slug==\"about-us\"\nExpand not needed but @select works: GET /api/v1/app/pages?@select=id,title,slug\nCurl: curl \"/api/v1/app/pages?page=1&limit=20\" → {data:[...], paginator}"]],
        '/api/v1/app/pages/{id}' => ['summary' => ['get' => 'Get page detail (public)'], 'desc' => ['get' => "Public page detail by id. Returns any status via detailFilter (strips status constraint) but list only shows published. No auth. Supports @select.\nExample: GET /api/v1/app/pages/1 → {data:{id,title,slug,body,metaTitle,metaDescription,status,publishedAt,createdAt}}\nCurl: curl /api/v1/app/pages/1 → 200 or 404\nPublished pages: GET /api/v1/app/pages?@filter=entity.title==\"About Us\"\nFor admin draft preview use GET /api/v1/manage/pages/{id} (ROLE_ADMIN)."]],
        '/api/v1/app/media' => ['summary' => ['get' => 'List my media (own, paginated)'], 'desc' => ['get' => "Paginated own media only (commonFilter: user=>currentUser). Requires JWT. Supports @filter, @order, @select, @sort, @expands, @display.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/media?page=1&limit=20&@order=createdAt|DESC → {data:[{id,filename,path,mimeType,size,alt,title,storage}], paginator}\nFilter by mime: GET /api/v1/app/media?@filter=entity.mimeType==\"image/jpeg\"\nFilter by storage: GET /api/v1/app/media?@filter=entity.storage==\"local\"\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" \"/api/v1/app/media?page=1&limit=20\" → 200\nManage is global (all users); app is own only."]],
        '/api/v1/app/media/upload' => ['summary' => ['post' => 'Upload my media file'], 'desc' => ['post' => "Authenticated multipart upload endpoint for current user. Send binary file in form field `file`.\nHeader: Authorization: Bearer <user_jwt> (multipart)\nForm fields: file (binary required), storage=local|qiniu (optional, default media.storage.default), category (int category id), alt, title, width, height.\nLocal stored under public/uploads/{YYYYMM}/ → /uploads/... URL. Qiniu requires qiniu.* settings + SDK.\nCurl:\ncurl -X POST /api/v1/app/media/upload \\\n  -H \"Authorization: Bearer <user_jwt>\" \\\n  -F file=@/tmp/photo.jpg -F storage=local -F category=1 -F alt=\"EDC\" -F title=\"Photo\"\n→ 201 {data:{id,filename,path:\"/uploads/2026/09/abc.jpg\"}}\nErrors: 400 No file / invalid mime (allowlist: image/jpeg,image/png,image/gif,image/webp,application/pdf) / max 10MB; 401 Auth."]],
        '/api/v1/app/media/{id}' => ['summary' => ['get' => 'Get my media detail (own)'], 'desc' => ['get' => "Get own media by id. Requires JWT; 404 if not own (commonFilter).\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/media/1 → {data:{id,filename,path,mimeType,size,alt,title,storage}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/media/1 → 200 or 404\nSupports @select; manage/media/{id} is admin (any user)."]],
        '/api/v1/public/media' => ['summary' => ['get' => 'List public media'], 'desc' => ['get' => "Anonymous read-only list. commonFilter {user IS NULL} returns only ownerless media. No auth required but JWT accepted. Paginated + @filter.\nExample: GET /api/v1/public/media?page=1&limit=20 → {data:[{id,filename,path,mimeType}], paginator}\nFilter by mime: GET /api/v1/public/media?@filter=entity.mimeType==\"image/jpeg\"\nCurl: curl /api/v1/public/media → {data:[...]} → ownerless only; user-owned hidden.\nTo upload, use /app/media/upload (own) or /manage/media/upload (admin)."]],
        '/api/v1/public/media/{id}' => ['summary' => ['get' => 'Get public media detail (anonymous)'], 'desc' => ['get' => "Anonymous read-only detail. Filter {user IS NULL} so 404 for user-owned media. No auth.\nExample: GET /api/v1/public/media/1 → {data:{id,filename,path}}\nCurl: curl /api/v1/public/media/1 → 200 (if ownerless) or 404"]],
        '/api/v1/app/settings' => ['summary' => ['get' => 'List settings (public/app)'], 'desc' => ['get' => "Public/app settings list (often filtered, but no ROLE_ADMIN required). Paginated + @filter. No auth required.\nExample: GET /api/v1/app/settings?page=1&limit=20 → {data:[{id,key,value,type,groupName}], paginator}\nFilter by key: GET /api/v1/app/settings?@filter=entity.key==\"site.name\"\nFilter by group: GET /api/v1/app/settings?@filter=entity.groupName==\"site\"\nCurl: curl /api/v1/app/settings → {data:[...]} → public visibility; manage/settings is admin."]],
        '/api/v1/app/settings/{id}' => ['summary' => ['get' => 'Get setting detail (public/app)'], 'desc' => ['get' => "Public/app setting detail by id. No auth.\nExample: GET /api/v1/app/settings/1 → {data:{id,key,value,type,groupName,label}}\nCurl: curl /api/v1/app/settings/1 → 200 or 404"]],

        '/api/v1/manage/wallets' => ['summary' => ['get' => 'List wallets (admin)', 'post' => 'Create wallet (admin)'], 'desc' => ['get' => "Paginated admin wallet list (all users). Supports @filter, @dql, @order, @select, @sort, @expands, @display. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/wallets?page=1&limit=20&@order=createdAt|DESC → {data:[{id,user:{id},currency,balance,status,label,createdAt}], paginator}\nFilter by currency: GET /api/v1/manage/wallets?@filter=entity.currency==\"CNY\"\nFilter by user: GET /api/v1/manage/wallets?@filter=entity.user.getId()==1\nFilter frozen: GET /api/v1/manage/wallets?@filter=entity.status==\"frozen\"\n@select: GET /api/v1/manage/wallets?@select=id,currency,balance\nExpand user: GET /api/v1/manage/wallets?@expands=user\nBalance is in cents (e.g. 10000 = ¥100.00).", 'post' => "Create wallet (admin, any user). One wallet per user per currency; balance starts at 0. ROLE_ADMIN. Required: user (int user id FK), currency (string e.g. CNY). Optional: label (string nullable, display name), status in [active,frozen] (default active).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/wallets \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"user\":1,\"currency\":\"CNY\",\"label\":\"Alice primary\",\"status\":\"active\"}'\n→ 201 {data:{id,currency:\"CNY\",balance:0,status:\"active\"}}\nPayload example: {\"user\":1,\"currency\":\"CNY\",\"label\":\"Alice primary\",\"status\":\"active\"}\nErrors: 400 Missing user/currency; 409 Duplicate user+currency wallet; 404 User not found.\nNote: currency immutable after creation (acceptedUpdateProperties only status,label)."]],
        '/api/v1/manage/wallets/batch-update' => ['summary' => ['post' => 'Batch update wallets (admin)'], 'desc' => ['post' => "Batch upsert wallets (admin). Query: @mode=mixed|strict|create, @basis=id, @partial bool.\nBody: JSON array of wallet objects (each may contain id, user, currency, label, status). Creation respects one-per-user-per-currency uniqueness.\nHeader: Authorization: Bearer <admin_jwt>\nExample:\ncurl -X POST \"/api/v1/manage/wallets/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"label\":\"Primary\",\"status\":\"frozen\"},{\"user\":2,\"currency\":\"CNY\",\"label\":\"Bob wallet\"}]'\n→ 200 [{...}]\nPayload example: [{\"id\":1,\"label\":\"Updated\"}]"]],
        '/api/v1/manage/wallets/{id}' => ['summary' => ['get' => 'Get wallet detail (admin)', 'put' => 'Update wallet (freeze/unfreeze) (admin)', 'delete' => 'Delete wallet (admin)'], 'desc' => ['get' => "Get wallet by id (admin, any user). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/wallets/1 → {data:{id,user:{id,username},currency,balance:10000,status:\"active\",label,createdAt}}\nBalance in cents: 10000 = ¥100.00. Curl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/wallets/1", 'put' => "Update wallet mutable fields: status in [active,frozen] (freeze/unfreeze), label (string nullable). Currency is immutable (acceptedUpdateProperties: status,label). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl (freeze): curl -X PUT /api/v1/manage/wallets/1 -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"status\":\"frozen\"}' → 200 {data:{status:\"frozen\"}}\nUnfreeze: {\"status\":\"active\"}. Update label: {\"label\":\"New label\"}.\nPayload example: {\"status\":\"frozen\",\"label\":\"Frozen for audit\"}\nErrors: 400 Invalid status; 404 Not found.", 'delete' => "Delete wallet. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/wallets/1 → 200 {code:0}\nCurl: curl -X DELETE -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/wallets/1\nNote: history retained in transactions/ledger; balance verification will surface gap."]],
        '/api/v1/manage/wallets/balance' => ['summary' => ['get' => 'Global wallet balance audit (admin)'], 'desc' => ['get' => "Global balance audit across ALL wallets. ROLE_ADMIN. Sums wallet balances vs ledger (transactions + vouchers). Returns {totalBalance, totalDeposited, discrepancy, matches: bool, wallets:[{id,balance,computed,delta}]}.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/wallets/balance → {data:{totalBalance:100000, totalDeposited:100000, discrepancy:0, matches:true}}\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/wallets/balance\nUse POST /manage/wallets/reconcile to fix gaps. Values in cents."]],
        '/api/v1/manage/wallets/reconcile' => ['summary' => ['post' => 'Reconcile wallet balances (admin)'], 'desc' => ['post' => "Fixes per-wallet balance gaps by creating TYPE_ADJUSTMENT transactions for discrepancies. ROLE_ADMIN. Idempotent gap repair.\nHeader: Authorization: Bearer <admin_jwt> (no body required; empty JSON {} accepted)\nCurl: curl -X POST /api/v1/manage/wallets/reconcile -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{}' → 200 {data:{reconciled:2, wallets:[{id,delta}]}}\n→ {data:{reconciled:<int>, wallets:[...]}}\nErrors: 401/403. Use GET /manage/wallets/balance before/after to verify."]],
        '/api/v1/app/wallets/balance' => ['summary' => ['get' => 'My wallet balance audit (own)'], 'desc' => ['get' => "Audits only current user wallets vs ledger. No ROLE_ADMIN required but JWT required (user-scoped). Returns {totalBalance, discrepancy, matches, wallets:[{id,balance,computed}]}.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/wallets/balance → {data:{totalBalance:50000, discrepancy:0, matches:true}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/wallets/balance → 200\nValues in cents."]],
        '/api/v1/manage/transactions' => ['summary' => ['get' => 'List wallet transactions (admin)', 'post' => 'Atomic wallet transfer (admin)'], 'desc' => ['get' => "Paginated transaction ledger (all wallets). Supports @filter, @order, @select. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/transactions?page=1&limit=20&@order=createdAt|DESC → {data:[{id,fromWallet:{id},toWallet:{id},amount,referenceId,description,type,status,createdAt}], paginator}\nFilter by reference: GET /api/v1/manage/transactions?@filter=entity.referenceId==\"order-42-payment\"\nFilter by amount: GET /api/v1/manage/transactions?@filter=entity.amount>=10000", 'post' => "Atomic, deadlock-safe (consistent lock ordering), idempotent via referenceId, currency match enforced. ROLE_ADMIN. Required: fromWalletId (int), toWalletId (int), amount (int cents >=1). Optional: referenceId (string idempotency key) → duplicate referenceId returns same transaction (no double-spend), description (string). Next referenceId reuse: POST same payload → 200 with original transaction (not new).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/transactions \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"fromWalletId\":1,\"toWalletId\":2,\"amount\":10000,\"referenceId\":\"order-42-payment\",\"description\":\"Payment for order #42\"}'\n→ 201 {data:{id,amount:10000,referenceId:\"order-42-payment\"}}  (10000 = ¥100.00)\nIdempotency: same referenceId + same wallets/amount → 200 original.\nPayload example: {\"fromWalletId\":1,\"toWalletId\":2,\"amount\":10000,\"referenceId\":\"txn-20250101-001\",\"description\":\"Payment\"}\nErrors: 400 Same wallet → 400 SameWalletTransferException; 402 Insufficient funds; 403 Wallet frozen; 404 Wallet not found; currency mismatch → 400."]],
        '/api/v1/manage/transactions/{id}' => ['summary' => ['get' => 'Get transaction detail (admin)'], 'desc' => ['get' => "Get transaction by id (ledger append-only, no PUT/DELETE). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/transactions/1 → {data:{id,fromWallet:{id},toWallet:{id},amount:10000,referenceId,description,type,createdAt}} → amount in cents.\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/transactions/1 → 200 or 404"]],
        '/api/v1/manage/vouchers/deposit' => ['summary' => ['post' => 'Voucher-backed deposit (manage, any wallet)'], 'desc' => ['post' => "Single-sided credit (fromWallet=null) into any wallet. ROLE_ADMIN. Voucher_type defaults to manual if omitted. Idempotent via referenceId (same referenceId returns same voucher). Required: walletId (int), amount (int cents >=1), currency (string e.g. CNY), referenceId (string idempotency key). Optional: voucherType (string default manual, admin can use any type), voucherId (string, defaults to referenceId), reason (string).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/vouchers/deposit \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"walletId\":1,\"amount\":50000,\"currency\":\"CNY\",\"referenceId\":\"deposit-001\",\"voucherType\":\"manual\",\"voucherId\":\"deposit-001\",\"reason\":\"Manual top-up\"}'\n→ 201 {data:{uuid,status:\"completed\",amount:50000}}\nPayload example: {\"walletId\":1,\"amount\":50000,\"currency\":\"CNY\",\"referenceId\":\"deposit-001\"}\nErrors: 400 Missing fields; 403 Wallet frozen; 404 Wallet not found; 403 Voucher type not permitted (rare for admin). Amount in cents."]],
        '/api/v1/manage/vouchers/withdraw' => ['summary' => ['post' => 'Voucher-backed withdrawal (manage, any wallet)'], 'desc' => ['post' => "Single-sided debit (toWallet=null) from any wallet. ROLE_ADMIN. Idempotent via referenceId. Required: walletId (int), amount (int cents), currency (string), referenceId (string). Optional: voucherType (default manual), voucherId (defaults to referenceId), reason.\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/vouchers/withdraw \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"walletId\":1,\"amount\":20000,\"currency\":\"CNY\",\"referenceId\":\"withdraw-001\",\"voucherType\":\"manual\",\"reason\":\"Cash out\"}'\n→ 201 {data:{uuid,status}}\nPayload example: {\"walletId\":1,\"amount\":20000,\"currency\":\"CNY\",\"referenceId\":\"withdraw-001\"}\nErrors: 402 Insufficient funds; 403 Frozen."]],
        '/api/v1/manage/vouchers/{uuid}/reverse' => ['summary' => ['post' => 'Reverse voucher (manage, any voucher)'], 'desc' => ['post' => "Reverse any voucher by its UUID. Returns funds to source wallet (credit_reversal / debit_reversal). ROLE_ADMIN. Body: reason (string optional but recommended).\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X POST /api/v1/manage/vouchers/<uuid>/reverse -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"reason\":\"Reversal due to error\"}' → 200 {data:{uuid,status:\"reversed\"}}\nErrors: 400 Already reversed; 404 Voucher not found."]],
        '/api/v1/app/vouchers/deposit' => ['summary' => ['post' => 'Self-service deposit (own wallet)'], 'desc' => ['post' => "Voucher-backed deposit into own wallet (user-scoped). Requires voucherType to match provider permission. Required: walletId (int, must be own), amount (int cents >=1), currency (string), voucherType (string required), voucherId (string), referenceId (string idempotency key). Optional: reason.\nHeader: Authorization: Bearer <user_jwt>\nCurl:\ncurl -X POST /api/v1/app/vouchers/deposit \\\n  -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"walletId\":1,\"amount\":50000,\"currency\":\"CNY\",\"voucherType\":\"manual\",\"voucherId\":\"dep-001\",\"referenceId\":\"dep-001\",\"reason\":\"Top-up\"}'\n→ 201 {data:{uuid}}\nPayload example: {\"walletId\":1,\"amount\":50000,\"currency\":\"CNY\",\"voucherType\":\"manual\",\"voucherId\":\"dep-001\",\"referenceId\":\"dep-001\"}\nErrors: 403 Own wallet check / voucherType not permitted; 403 Frozen."]],
        '/api/v1/app/vouchers/withdraw' => ['summary' => ['post' => 'Self-service withdrawal (own wallet)'], 'desc' => ['post' => "Voucher-backed withdrawal from own wallet (user-scoped). Required: walletId (int own), amount (int), currency, voucherType, voucherId, referenceId. Optional: reason.\nHeader: Authorization: Bearer <user_jwt>\nCurl: curl -X POST /api/v1/app/vouchers/withdraw -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" -d '{\"walletId\":1,\"amount\":20000,\"currency\":\"CNY\",\"voucherType\":\"manual\",\"voucherId\":\"wit-001\",\"referenceId\":\"wit-001\"}' → 201\nPayload example: {\"walletId\":1,\"amount\":20000,\"currency\":\"CNY\",\"voucherType\":\"manual\",\"voucherId\":\"wit-001\",\"referenceId\":\"wit-001\"}\nErrors: 402 Insufficient funds; 403 Not own wallet."]],
        '/api/v1/app/vouchers/{uuid}/reverse' => ['summary' => ['post' => 'Reverse own voucher (self)'], 'desc' => ['post' => "Reverse own voucher by direction (user-scoped, must be own voucher). Body: reason (string optional).\nHeader: Authorization: Bearer <user_jwt>\nCurl: curl -X POST /api/v1/app/vouchers/<uuid>/reverse -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" -d '{\"reason\":\"Requested reversal\"}' → 200\nErrors: 403 Not own voucher; 400 Already reversed; 404 Not found."]],
        '/api/v1/manage/invoices' => ['summary' => ['get' => 'List invoices (admin)', 'post' => 'Create invoice (admin)'], 'desc' => ['get' => "Paginated admin invoice list (all payers). Supports @filter, @order, @select, @expands. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/invoices?page=1&limit=20&@order=createdAt|DESC → {data:[{id,uuid,sourceType,sourceId,scene,amount,currency,status,payer:{id},subject}], paginator}\nFilter by status: GET /api/v1/manage/invoices?@filter=entity.status==\"pending\"\nFilter by scene: GET /api/v1/manage/invoices?@filter=entity.scene==\"order\"\nFilter by payer: GET /api/v1/manage/invoices?@filter=entity.payer.getId()==1", 'post' => "Create pending invoice. ROLE_ADMIN. Required: sourceType (string e.g. order|deposit|topup), sourceId (string, business id), scene (string e.g. order|wallet_deposit), amount (int cents, or float/\"12.34\" → cents via parseAmount), currency (string default CNY). Optional: payer (int user id nullable), subject (string), description (string), extraData (object).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/invoices \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"sourceType\":\"order\",\"sourceId\":\"42\",\"scene\":\"order\",\"amount\":19900,\"currency\":\"CNY\",\"payer\":1,\"subject\":\"Order #42 payment\",\"description\":\"Payment for order 42\"}'\n→ 201 {data:{id,uuid,status:\"pending\",amount:19900}}\nAmount examples: 19900 = ¥199.00, \"19.90\" also accepted → 1990 (float→cents). Float 19.9 → 1990.\nPayload example: {\"sourceType\":\"order\",\"sourceId\":\"42\",\"scene\":\"order\",\"amount\":19900,\"currency\":\"CNY\",\"payer\":1}\nErrors: 400 Missing sourceType/sourceId/scene/amount; 401/403 auth."]],
        '/api/v1/manage/invoices/{id}' => ['summary' => ['get' => 'Get invoice detail (admin)'], 'desc' => ['get' => "Get invoice by id (admin). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/invoices/1 → {data:{id,uuid,sourceType,sourceId,scene,amount,currency,status,payer:{id},subject,description,extraData,createdAt}}\nStatus enum: pending|paying|paid|cancelled|refunding|refunded. Amount in cents.\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/invoices/1 → 200 or 404"]],
        '/api/v1/manage/invoices/{id}/pay/{payment}' => ['summary' => ['post' => 'Pay invoice via gateway (manage)'], 'desc' => ['post' => "Pay pending invoice via gateway {payment}. ROLE_ADMIN. Path param payment in [mock,wallet,wechat]. Applies wallet_balance adjustments then calls gateway pay(payment, amount). Body: walletId (int optional for wallet_balance deduction), amount (int cents optional). For wallet payment: {payment}=wallet uses system/user wallets; for wechat/mock autoPaid handled server-side.\nHeader: Authorization: Bearer <admin_jwt>\nCurl (mock immediate):\ncurl -X POST /api/v1/manage/invoices/1/pay/mock -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{}' → 200 {data:{status:\"paid\"}}\nCurl (wallet): curl -X POST /api/v1/manage/invoices/1/pay/wallet -H \"Authorization: Bearer <admin_jwt>\" -d '{\"walletId\":1,\"amount\":19900}'\nCurl (wechat): curl -X POST /api/v1/manage/invoices/1/pay/wechat -d '{}' → 200 {payload:{timeStamp,nonceStr,package,signType,paySign}} (for wx.requestPayment)\nErrors: 400 Already paid/cancelled; 404 Invoice not found."]],
        '/api/v1/manage/invoices/{id}/cancel' => ['summary' => ['post' => 'Cancel invoice (admin)'], 'desc' => ['post' => "Cancel pending/paying invoice. ROLE_ADMIN. Body: reason (string nullable). Only pending/paying allowed → 400 otherwise.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X POST /api/v1/manage/invoices/1/cancel -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"reason\":\"User requested\"}' → 200 {data:{status:\"cancelled\"}}\nPayload example: {\"reason\":\"Duplicate order\"}\nErrors: 400 Already paid/cancelled → InvalidTransition; 404 Not found."]],
        '/api/v1/manage/invoices/{id}/refund' => ['summary' => ['post' => 'Refund invoice (admin)'], 'desc' => ['post' => "Refund paid invoice (partial or full) with reason. ROLE_ADMIN. Required: amount (int cents or float→cents via parseAmount), reason (string). Optional extra fields forwarded to service (e.g. walletId for wallet refunds). Updates refundedAmount.\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/invoices/1/refund \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"amount\":5000,\"reason\":\"Partial refund for defect\"}'\n→ 200 {data:{status:\"refunded\",refundedAmount:5000}}\nFull refund: {\"amount\":19900,\"reason\":\"Customer changed mind\"}\nPayload example: {\"amount\":5000,\"reason\":\"Defect\"}\nErrors: 400 Missing amount/reason / exceeds paid amount; 404 Not found."]],
        '/api/v1/manage/invoices/{id}/transitions' => ['summary' => ['get' => 'Invoice available transitions (admin)'], 'desc' => ['get' => "Get enabled workflow transitions for invoice id. ROLE_ADMIN. Returns [{name:\"pay\"},{name:\"cancel\"},...] for current status.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/invoices/1/transitions → {data:[{name:\"pay\"},{name:\"cancel\"}]}\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/invoices/1/transitions"]],
        '/api/v1/app/invoices/{id}/pay/{payment}' => ['summary' => ['post' => 'Pay invoice (self, own payer only)'], 'desc' => ['post' => "User pays own invoice via gateway {payment} (must be own payer). Same adjustment pipeline as manage (wallet_balance deduction then gateway). Path payment in [mock,wallet,wechat]. Body: walletId (int), amount (int cents) optional for wallet deductions.\nHeader: Authorization: Bearer <user_jwt>\nCurl (wallet): curl -X POST /api/v1/app/invoices/1/pay/wallet -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" -d '{\"walletId\":1}' → 200\nCurl (mock): curl -X POST /api/v1/app/invoices/1/pay/mock -H \"Authorization: Bearer <user_jwt>\" -d '{}'\nCurl (wechat): → 200 {payload:{timeStamp,nonceStr,package,signType,paySign}} for wx.requestPayment\nErrors: 404 Not own invoice; 400 Invalid state."]],
        '/api/v1/app/authorization/me' => ['summary' => ['get' => 'Get my authorization (own permissions)'], 'desc' => ['get' => "Returns effective permissions, storeScopes, fieldGrants for current user. Requires JWT (ROLE_USER). No @filter.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/authorization/me → {data:{permissions:[\"store:order:fulfill\"],storeScopes:[{storeUuid,role}],fieldGrants:{\"product\":{\"read\":[\"name\"]}}}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/authorization/me"]],
        '/api/v1/app/invoices' => ['summary' => ['get' => 'List my invoices (own)'], 'desc' => ['get' => "Paginated own invoices only (commonFilter: payer=>currentUser). Requires JWT (own). Supports @filter, @order.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/invoices?page=1&limit=20&@order=createdAt|DESC → {data:[{id,uuid,sourceType,sourceId,scene,amount,currency,status,subject}], paginator}\nFilter by status: GET /api/v1/app/invoices?@filter=entity.status==\"paid\"\nFilter by scene: GET /api/v1/app/invoices?@filter=entity.scene==\"order\"\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/invoices → 200 (own only)\nManage is global (all payers); app is own only."]],
        '/api/v1/app/invoices/{id}' => ['summary' => ['get' => 'Get invoice detail (own)'], 'desc' => ['get' => "Get own invoice by id. Requires JWT; 404 if not own (commonFilter payer=>currentUser).\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/invoices/1 → {data:{id,uuid,sourceType,sourceId,scene,amount:19900,currency:\"CNY\",status:\"pending\",subject}}\nAmount in cents: 19900 = ¥199.00.\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/invoices/1 → 200 or 404\nSupports @select."]],
        '/api/v1/app/pictures' => ['summary' => ['get' => 'List my pictures (own)', 'post' => 'Create picture (own)'], 'desc' => ['get' => "Paginated own pictures only (commonFilter: user=>currentUser). Requires JWT (author-scoped). Supports @filter, @order, @select, @expands.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/pictures?page=1&limit=20&@order=createdAt|DESC → {data:[{id,title,category:{id},image:{id},metadata,user:{id}}], paginator}\nFilter by title: GET /api/v1/app/pictures?@filter=entity.title==\"Summer\"\nSupports @select: ?@select=id,title\nApp manage/pictures is admin (any user); this is own only.", 'post' => "Create picture as authenticated user. Required: category (int category id), image (int media id or string path/uuid). Optional: title (string nullable), metadata (object nullable). User auto-bound to current user (processEntity).\nHeader: Authorization: Bearer <user_jwt>\nCurl:\ncurl -X POST /api/v1/app/pictures \\\n  -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"category\":1,\"image\":1,\"title\":\"Summer Trip\",\"metadata\":{\"location\":\"Xuhui\"}}'\n→ 201 {data:{id,title,category:{id}}}\nPayload example: {\"category\":1,\"image\":1,\"title\":\"Summer Trip\"}\nErrors: 400 Missing category/image; 404 Category/media not found; 401 Not authenticated."]],
        '/api/v1/app/pictures/{id}' => ['summary' => ['get' => 'Get picture detail (own)', 'put' => 'Update picture (own)', 'delete' => 'Delete picture (own)'], 'desc' => ['get' => "Get own picture by id. Requires JWT; commonFilter user=>currentUser means 404 if not own.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/pictures/1 → {data:{id,title,category:{id},image:{id},metadata,user:{id}}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/pictures/1 → 200 or 404\nSupports @select, @expands=category,image.", 'put' => "Update own picture: title, category (int), image (int), metadata. Requires JWT and own.\nHeader: Authorization: Bearer <user_jwt>\nCurl: curl -X PUT /api/v1/app/pictures/1 -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" -d '{\"title\":\"Summer V2\",\"category\":2}' → 200\nPayload example: {\"title\":\"Summer V2\",\"category\":2,\"image\":1,\"metadata\":null}", 'delete' => "Delete own picture. Requires JWT (own only).\nHeader: Authorization: Bearer <user_jwt>\nExample: DELETE /api/v1/app/pictures/1 → 200 {code:0}\nCurl: curl -X DELETE -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/pictures/1"]],
        '/api/v1/app/pictures/batch-update' => ['summary' => ['post' => 'Batch update pictures (own)'], 'desc' => ['post' => "Batch upsert own pictures (user-scoped). Query: @mode=mixed|strict|create, @basis=id, @partial bool.\nBody: JSON array of picture objects (each may contain id, category, image, title, metadata). Own filter applied.\nHeader: Authorization: Bearer <user_jwt>\nExample:\ncurl -X POST \"/api/v1/app/pictures/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"title\":\"Updated\"},{\"category\":1,\"image\":2,\"title\":\"New\"}]'\n→ 200\nPayload example: [{\"id\":1,\"title\":\"Updated\"}]"]],
        '/api/v1/manage/pictures' => ['summary' => ['get' => 'List pictures (admin)', 'post' => 'Create picture (admin)'], 'desc' => ['get' => "Paginated admin picture list (all users, no user filter). Supports @filter, @order, @select, @expands. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/pictures?page=1&limit=20&@order=createdAt|DESC → {data:[{id,title,category:{id},image:{id},metadata,user:{id}}], paginator}\nFilter by user: GET /api/v1/manage/pictures?@filter=entity.user.getId()==1\nFilter by category: GET /api/v1/manage/pictures?@filter=entity.category==1\nAdmin can see all, app is own only.", 'post' => "Create picture (admin, any user). Required: category (int), image (int media id). Optional: user (int user id, admin can assign), title, metadata. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/pictures \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"category\":1,\"image\":1,\"user\":1,\"title\":\"Admin Pic\",\"metadata\":{\"source\":\"admin\"}}'\n→ 201 {data:{id}}\nPayload example: {\"category\":1,\"image\":1,\"title\":\"Admin Pic\"}\nErrors: 400 Missing category/image; 404 Category not found."]],
        '/api/v1/manage/pictures/batch-update' => ['summary' => ['post' => 'Batch update pictures (admin)'], 'desc' => ['post' => "Batch upsert pictures (admin). Query: @mode=mixed|strict|create, @basis=id, @partial bool.\nBody: JSON array of picture objects (each may contain id, category, image, title, metadata, user).\nHeader: Authorization: Bearer <admin_jwt>\nExample: curl -X POST \"/api/v1/manage/pictures/batch-update?@mode=mixed&@basis=id\" -d '[{\"id\":1,\"title\":\"Updated\"}]' → 200"]],
        '/api/v1/manage/pictures/{id}' => ['summary' => ['get' => 'Get picture detail (admin)', 'put' => 'Update picture (admin)', 'delete' => 'Delete picture (admin)'], 'desc' => ['get' => "Get picture by id (admin, any user). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/pictures/1 → {data:{id,title,category:{id},image:{id},metadata,user:{id}}}", 'put' => "Update picture: user, title, category, image, metadata. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/pictures/1 -H \"Authorization: Bearer <admin_jwt>\" -d '{\"title\":\"New title\"}' → 200", 'delete' => "Delete picture (admin). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/pictures/1 → 200"]],
        '/api/v1/app/promotions' => ['summary' => ['get' => 'List promotions (public)'], 'desc' => ['get' => "Public active-only promotion list. commonFilter {enabled:true, deleted:false} + time window (startTime<=now<=endTime) applied server-side. No auth required. Paginated + @filter/@order.\nExample: GET /api/v1/app/promotions?page=1&limit=20&@order=startTime|DESC → {data:[{id,name,description,template:{id},storeCode,enabled,startTime,endTime,config,conflictMode}], paginator}\nFilter by template: GET /api/v1/app/promotions?@filter=entity.template.getId()==1\nFilter by storeCode: GET /api/v1/app/promotions?@filter=entity.storeCode==\"XUHUI\"\nCurl: curl /api/v1/app/promotions → {data:[...]} → only active/time-valid promotions."]],
        '/api/v1/app/promotions/{id}' => ['summary' => ['get' => 'Get promotion detail (public)'], 'desc' => ['get' => "Public promotion detail by id. Returns active, time-valid promotion. No auth.\nExample: GET /api/v1/app/promotions/1 → {data:{id,name,description,template:{id,name},storeCode,enabled,startTime,endTime,config}}\nCurl: curl /api/v1/app/promotions/1 → 200 or 404 if inactive/expired. Supports @select, @expands=template."]],
        '/api/v1/app/specifications' => ['summary' => ['get' => 'List specifications (public)'], 'desc' => ['get' => "Public active-only spec list. commonFilter {specStatus:active, specIsDeleted:false, productStatus:active, productIsDeleted:false, store:null|scoped}. Without X-Store-Code returns global specs only; with X-Store-Code merges global + scoped. No auth.\nPaginated + @filter/@order.\nExamples:\ncurl /api/v1/app/specifications?page=1&limit=20 → {data:[{id,uuid,name,price,status,sort,product:{id}}]}\nBy product via query: GET /api/v1/app/specifications?@filter=entity.product.getId()==1\nWith store: curl -H \"X-Store-Code: XUHUI\" /api/v1/app/specifications\nPrice in cents: 699900 = ¥6999.00. Sort: ?@order=price|ASC"]],
        '/api/v1/app/specifications/{id}' => ['summary' => ['get' => 'Get specification detail (public)'], 'desc' => ['get' => "Public spec detail by id. Returns active spec with active product; respects X-Store-Code scoping. No auth.\nExample: GET /api/v1/app/specifications/10 → {data:{id,uuid,name,price,status,sort,product:{id,uuid,name}}}\nPrice in cents: 699900 = ¥6999.00.\nCurl: curl /api/v1/app/specifications/10 → 200 or 404 if inactive/deleted or store mismatch"]],
        '/api/v1/app/store-orders/{id}' => ['summary' => ['get' => 'Get my store order detail (own)'], 'desc' => ['get' => "Own StoreOrder only. Example: GET /api/v1/app/store-orders/{uuid}  Header: Authorization: Bearer <user_jwt> → {data:{uuid,operationalStatus}}"]],
        '/api/v1/app/stores/{uuid}/membership' => ['summary' => ['get' => 'Get my store membership', 'post' => 'Join store'], 'desc' => ['get' => "Example: GET /api/v1/app/stores/{uuid}/membership → {data:{role}}", 'post' => "Example: POST /api/v1/app/stores/{uuid}/membership {} → 201"]],
        '/api/v1/app/transactions' => ['summary' => ['get' => 'List my transactions (own)'], 'desc' => ['get' => "Paginated own transaction ledger (user-scoped via wallet.user). Requires JWT. Supports @filter, @order.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/transactions?page=1&limit=20&@order=createdAt|DESC → {data:[{id,fromWallet:{id},toWallet:{id},amount,referenceId,description}], paginator}\nFilter by reference: GET /api/v1/app/transactions?@filter=entity.referenceId==\"order-42\"\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/transactions → {data:[...]} → only own wallets."]],
        '/api/v1/app/transactions/{id}' => ['summary' => ['get' => 'Get transaction detail (own)'], 'desc' => ['get' => "Get own transaction by id (must belong to own wallet). Requires JWT; 404 if not own.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/transactions/1 → {data:{id,amount:10000,referenceId}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/transactions/1"]],
        '/api/v1/app/vouchers' => ['summary' => ['get' => 'List my vouchers (own)'], 'desc' => ['get' => "Paginated own vouchers (user-scoped). Requires JWT. Supports @filter.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/vouchers?page=1&limit=20 → {data:[{uuid,wallet:{id},voucherType,amount,currency,status,referenceId}], paginator}\nFilter by type: GET /api/v1/app/vouchers?@filter=entity.voucherType==\"manual\"\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/vouchers"]],
        '/api/v1/app/vouchers/{id}' => ['summary' => ['get' => 'Get voucher detail (own)'], 'desc' => ['get' => "Get own voucher by id/uuid. Requires JWT; 404 if not own.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/vouchers/<uuid> → {data:{uuid,amount,status}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/vouchers/<uuid>"]],
        '/api/v1/app/wallets' => ['summary' => ['get' => 'List my wallets (own)'], 'desc' => ['get' => "Paginated own wallets only (commonFilter: user=>currentUser). Requires JWT. Supports @filter, @order.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/wallets?page=1&limit=20 → {data:[{id,currency,balance:10000,status,label}], paginator}\nFilter by currency: GET /api/v1/app/wallets?@filter=entity.currency==\"CNY\"\nBalance in cents: 10000 = ¥100.00.\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/wallets"]],
        '/api/v1/app/wallets/{id}' => ['summary' => ['get' => 'Get wallet detail (own)'], 'desc' => ['get' => "Get own wallet by id. Requires JWT; 404 if not own.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/wallets/1 → {data:{id,currency,balance,status}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/wallets/1"]],
        '/api/v1/app/payment-deductions' => ['summary' => ['get' => 'List my payment deductions (own)'], 'desc' => ['get' => "Paginated own payment deductions (user-scoped). Requires JWT.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/payment-deductions?page=1&limit=20 → {data:[{id,amount,currency,invoice:{id}}], paginator}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/payment-deductions"]],
        '/api/v1/app/payment-deductions/{id}' => ['summary' => ['get' => 'Get payment deduction detail (own)'], 'desc' => ['get' => "Get own payment deduction by id. Requires JWT; 404 if not own.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/payment-deductions/1 → {data:{id,amount}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/payment-deductions/1"]],
        '/api/v1/app/voucher-comments' => ['summary' => ['get' => 'List voucher comments (own/all)', 'post' => 'Create voucher comment (own)'], 'desc' => ['get' => "Paginated voucher comments. Requires JWT (user-scoped where applicable). Supports @filter.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/voucher-comments?page=1&limit=20 → {data:[{id,body,voucher:{uuid}}], paginator}", 'post' => "Create voucher comment. Requires JWT. Body: voucher (voucher id/uuid), body (text), etc. User auto-bound.\nHeader: Authorization: Bearer <user_jwt>\nCurl: curl -X POST /api/v1/app/voucher-comments -H \"Authorization: Bearer <user_jwt>\" -H \"Content-Type: application/json\" -d '{\"voucher\":1,\"body\":\"Note\"}' → 201\nPayload example: {\"voucher\":1,\"body\":\"Note\"}"]],
        '/api/v1/app/voucher-comments/{id}' => ['summary' => ['get' => 'Get voucher comment detail', 'put' => 'Update voucher comment (own)', 'delete' => 'Delete voucher comment (own)'], 'desc' => ['get' => "Get voucher comment by id. Requires JWT.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/voucher-comments/1 → {data:{id,body}}", 'put' => "Update own voucher comment: body. Requires JWT and own.\nHeader: Authorization: Bearer <user_jwt>\nCurl: curl -X PUT /api/v1/app/voucher-comments/1 -H \"Authorization: Bearer <user_jwt>\" -d '{\"body\":\"Updated\"}' → 200", 'delete' => "Delete own voucher comment. Requires JWT.\nHeader: Authorization: Bearer <user_jwt>\nExample: DELETE /api/v1/app/voucher-comments/1 → 200"]],
        '/api/v1/app/wechat-users' => ['summary' => ['get' => 'List my WeChat users (own)'], 'desc' => ['get' => "Paginated own WeChat bindings (user-scoped). Requires JWT.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/wechat-users?page=1&limit=20 → {data:[{id,openid,unionid,nickname}], paginator}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/wechat-users"]],
        '/api/v1/app/wechat-users/{id}' => ['summary' => ['get' => 'Get WeChat user detail (own)'], 'desc' => ['get' => "Get own WeChat user by id. Requires JWT; 404 if not own.\nHeader: Authorization: Bearer <user_jwt>\nExample: GET /api/v1/app/wechat-users/1 → {data:{id,openid}}\nCurl: curl -H \"Authorization: Bearer <user_jwt>\" /api/v1/app/wechat-users/1"]],
        '/api/v1/app/wechat-users/batch-update' => ['summary' => ['post' => 'Batch update WeChat users (own)'], 'desc' => ['post' => "Batch upsert own WeChat users. Query: @mode, @basis, @partial. Requires JWT (own scoped).\nHeader: Authorization: Bearer <user_jwt>\nExample: POST /api/v1/app/wechat-users/batch-update?@mode=mixed&@basis=id -d '[{\"id\":1,\"nickname\":\"New\"}]' → 200"]],
        '/api/v1/manage/stores' => ['summary' => ['get' => 'List stores', 'post' => 'Create store'], 'desc' => ['get' => "Example: GET /api/v1/manage/stores → {data:[{uuid,code,name,status}]}\nHeader: Authorization: Bearer <admin_jwt>", 'post' => "Code must be unique. ROLE_ADMIN.\nExample: POST /api/v1/manage/stores\n{\n  \"code\":\"xuhui\",\n  \"name\":\"Xuhui Store\",\n  \"timezone\":\"Asia/Shanghai\",\n  \"settings\":{\"fulfillment\":{\"requireVerification\":true}}\n}\n→ 201 {data:{uuid}}\nOnly fulfillment.requireVerification is effective (order.requireAcceptance removed)."]],
        '/api/v1/manage/stores/{uuid}' => ['summary' => ['get' => 'Get store detail', 'put' => 'Update store'], 'desc' => ['get' => "Example: GET /api/v1/manage/stores/{uuid} → {data:{uuid,code,settings}}\nHeader: Authorization: Bearer <admin_jwt>", 'put' => "Example: PUT /api/v1/manage/stores/{uuid}\n{\"name\":\"Xuhui V2\",\"settings\":{\"fulfillment\":{\"requireVerification\":false}}} → 200"]],
        '/api/v1/manage/stores/{uuid}/status/{status}' => ['summary' => ['post' => 'Change store status'], 'desc' => ['post' => "Transitions: activate|suspend|close. ROLE_ADMIN.\nExample: POST /api/v1/manage/stores/{uuid}/status/suspend → 200"]],
        '/api/v1/manage/stores/{uuid}/members' => ['summary' => ['get' => 'List store members', 'post' => 'Grant store member'], 'desc' => ['get' => "Returns membership list. Example: GET /api/v1/manage/stores/{uuid}/members → {data:[{userUuid,role}]}\nHeader: Authorization: Bearer <admin_jwt>", 'post' => "Body: userUuid, role in [owner,manager,clerk,fulfillment]. Example: POST /api/v1/manage/stores/{uuid}/members {\"userUuid\":\"550e8400-e29b-41d4-a716-446655440000\",\"role\":\"manager\"} → 201"]],
        '/api/v1/app/stores' => ['summary' => ['get' => 'List active stores (public)'], 'desc' => ['get' => "No auth for discovery. Example: GET /api/v1/app/stores → {data:[{uuid,code,name}]}\nReturns active stores only."]],
        '/api/v1/app/stores/{id}' => ['summary' => ['get' => 'Get store detail (public)'], 'desc' => ['get' => "Example: GET /api/v1/app/stores/{uuid} → {data:{uuid,code,name,settings}}"]],
        '/api/v1/manage/store-orders' => ['summary' => ['get' => 'List store orders'], 'desc' => ['get' => "Admin view. Supports @filter.\nExample: GET /api/v1/manage/store-orders?@filter=entity.getStore().getCode()==\"xuhui\"  Header: Authorization: Bearer <admin_jwt>"]],
        '/api/v1/app/store-orders' => ['summary' => ['get' => 'List my store orders'], 'desc' => ['get' => "Own StoreOrders via customerUserUuid.\nExample: GET /api/v1/app/store-orders  Header: Authorization: Bearer <user_jwt> → {data:[{uuid,tradeOrderUuid,operationalStatus}]}\nDetail: GET /api/v1/app/store-orders/{uuid}"]],
        '/api/v1/app/store-orders/{uuid}' => ['summary' => ['get' => 'Get my store order detail'], 'desc' => ['get' => "Own StoreOrder only. Example: GET /api/v1/app/store-orders/{uuid}  Header: Authorization: Bearer <user_jwt> → 200 or 404"]],
        '/api/v1/store/{scopeId}/orders' => ['summary' => ['get' => 'List scoped store orders'], 'desc' => ['get' => "Staff scoped list via store uuid. Example: GET /api/v1/store/{storeUuid}/orders?page=1&limit=20  Headers: Authorization: Bearer <staff_jwt>\nRequires store membership (store:order:read) via Assignment scopeType=store.→ {data:[{uuid,tradeOrderUuid,operationalStatus}]}\nSupports @filter, @order."]],
        '/api/v1/store/{scopeId}/orders/{orderUuid}' => ['summary' => ['get' => 'Get scoped store order detail'], 'desc' => ['get' => "Example: GET /api/v1/store/{storeUuid}/orders/{tradeOrderUuid}  Header: Authorization: Bearer <staff_jwt>\norderUuid can be TradeOrder UUID or StoreOrder UUID. Requires membership (store:order:read). → 200 {data:{uuid,operationalStatus,verificationRequired,verifiedAt}} or 404 if not in store scope."]],
        '/api/v1/store/{scopeId}/orders/{orderUuid}/fulfill' => ['summary' => ['post' => 'Fulfill store order'], 'desc' => ['post' => "Staff fulfillment step. Requires store:order:fulfill (owner|manager|fulfillment). Order must be accepted.\nExample: POST /api/v1/store/{storeUuid}/orders/{tradeOrderUuid}/fulfill\nHeader: Authorization: Bearer <staff_jwt>\nBody: {\"fulfillmentData\":{\"mode\":\"pickup\",\"note\":\"ready\"}}\n→ 200 {data:{operationalStatus:\"fulfilled\"}}\nError 400 if wrong status (pending_validation/awaiting_inventory/rejected/cancelled/fulfilled/verified).\nNext step if verificationRequired=true: POST .../verify (no body)."]],
        '/api/v1/store/{scopeId}/orders/{orderUuid}/verify' => ['summary' => ['post' => 'Verify store order (complete Trade order)'], 'desc' => ['post' => "Staff verification post-fulfill. Requires store:order:verify (owner|manager|fulfillment).\nRequires StoreOrder fulfilled AND snapshotted verificationRequired=true (from Store fulfillment.requireVerification at order creation). Immutable per order.\nNo body required; order UUID is the token (empty JSON {} accepted).\nEmits store.order.verified.v1 → Trade fulfilled→completed (or deferred if fulfilled not yet reached, auto-completes right after fulfill via OrderVerificationCompletionListener).\n\nExample success:\nPOST /api/v1/store/{storeUuid}/orders/{tradeOrderUuid}/verify  Header: Authorization: Bearer <staff_jwt>  Body: {}\n→ 200 {code:0, data:{uuid, operationalStatus:\"verified\", verifiedAt:\"2026-09-03T08:00:00+00:00\", verifiedBy:\"staff-uuid\"}}\nTradeOrder polled via GET /api/v1/manage/orders/{tradeOrderId} will show status:\"completed\" after worker relay (5s scheduler).\n\nErrors:\n400 Store verification is disabled. (verificationRequired=false snapshot)\n400 Store order cannot be verified in its current status. (not fulfilled)\n400 Store order already verified. (verifiedAt not null)\n404 Store order not found or access denied. (wrong store scope or uuid)\n403 Forbidden (missing store membership)\n\nFull flow example (store_verification mode):\n1. PUT /manage/stores/{uuid} {\"settings\":{\"fulfillment\":{\"requireVerification\":true}}}\n2. POST /app/orders + Header X-Store-Code: XUHUI {\"items\":[{\"specificationId\":1,\"quantity\":1}]}\n3. POST /app/orders/{id}/confirm + POST /app/orders/{id}/payment {\"payment\":\"mock\",\"autoPaid\":true} → paid\n4. POST /manage/orders/{id}/fulfill {\"trackingNumber\":\"SF123\"} → fulfilled (Trade) — if verify already done, this also completes\n   POST /store/{storeUuid}/orders/{tradeOrderUuid}/fulfill {\"fulfillmentData\":{}} → fulfilled (Store)\n5. POST /store/{storeUuid}/orders/{tradeOrderUuid}/verify {} → verified + Trade completed\n6. GET /manage/orders/{id} → status:completed"]],
        '/api/v1/manage/inventory/materials' => ['summary' => ['get' => 'List materials (admin)', 'post' => 'Create material (admin)'], 'desc' => ['get' => "Paginated admin material list. Supports @filter, @order, @select. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/inventory/materials?page=1&limit=20&@order=code|ASC → {data:[{id,uuid,code,name,kind,unit,status,metadata}], paginator}\nFilter by kind: GET /api/v1/manage/inventory/materials?@filter=entity.kind==\"consumable\"\nFilter by code: GET /api/v1/manage/inventory/materials?@filter=entity.code==\"COFFEE_BEANS\"", 'post' => "Create material. ROLE_ADMIN. Required: code (string unique, e.g. \"COFFEE_BEANS\"), name (string), kind (string e.g. consumable|packaging), unit (string e.g. g|ml|pcs). Optional: status (string default active), metadata (object). Code is immutable after first stock mutation (frozen).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/inventory/materials \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"code\":\"COFFEE_BEANS\",\"name\":\"Coffee Beans\",\"kind\":\"consumable\",\"unit\":\"g\",\"status\":\"active\",\"metadata\":{\"origin\":\"Colombia\"}}'\n→ 201 {data:{uuid,code}}\nPayload example: {\"code\":\"COFFEE_BEANS\",\"name\":\"Coffee Beans\",\"kind\":\"consumable\",\"unit\":\"g\"}\nErrors: 400 Missing fields; 409 Duplicate code."]],
        '/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}' => ['summary' => ['get' => 'Get stock (virtual zero if absent) (admin)'], 'desc' => ['get' => "Per-store per-material stock view. ROLE_ADMIN. Path params: storeUuid (Store UUID), materialUuid (Material UUID). Returns {storeUuid,materialUuid,onHand (bcmath string), reserved, available, allowNegativeStock, updatedAt}. Virtual zero if no ledger rows yet.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/inventory/stocks/550e8400-e29b-41d4-a716-446655440000/550e8400-e29b-41d4-a716-446655440001 → {data:{onHand:\"100.000\", reserved:\"10.000\", allowNegativeStock:false}}\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}\nErrors: 404 Store or Material not found (invalid UUID)."]],
        '/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/adjust' => ['summary' => ['post' => 'Adjust stock (admin)'], 'desc' => ['post' => "Append-only stock ledger adjustment. ROLE_ADMIN. Path params: storeUuid, materialUuid. Required: quantityDelta (string bcmath, e.g. \"10.000\" or \"-5.500\"), reason (string). Optional: referenceId (string idempotency key), allowNegativeStock (bool, per-call override; persisted policy via /policy).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/adjust \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"quantityDelta\":\"10.000\",\"reason\":\"Initial stock\",\"referenceId\":\"init-001\",\"allowNegativeStock\":false}'\n→ 200 {data:{onHand:\"10.000\"}}\nNegative adjust: {\"quantityDelta\":\"-2.500\",\"reason\":\"Consumption\"}\nPayload example: {\"quantityDelta\":\"10.000\",\"reason\":\"Purchase\",\"referenceId\":\"po-001\"}\nErrors: 400 Invalid bcmath / insufficient stock when allowNegativeStock=false; 404 Store/Material not found."]],
        '/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/policy' => ['summary' => ['put' => 'Update stock policy (admin)'], 'desc' => ['put' => "Update per-store-material allowNegativeStock flag (persisted). ROLE_ADMIN. Path params: storeUuid, materialUuid. Required: allowNegativeStock (bool).\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/policy -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{\"allowNegativeStock\":true}' → 200 {data:{allowNegativeStock:true}}\nPayload example: {\"allowNegativeStock\":true}\nErrors: 400 Missing bool; 404 Not found."]],
        '/api/v1/manage/inventory/recipes' => ['summary' => ['get' => 'List recipes (admin)', 'post' => 'Create recipe (admin)'], 'desc' => ['get' => "Paginated recipe list (one active per specification UUID). Supports @filter. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/inventory/recipes?page=1&limit=20 → {data:[{id,specificationUuid,lines:[{materialUuid,quantityPerUnit,sort}],status}], paginator}\nFilter by spec: GET /api/v1/manage/inventory/recipes?@filter=entity.specificationUuid==\"550e8400-...\"", 'post' => "Create recipe: one active recipe per specification UUID. ROLE_ADMIN. Required: specificationUuid (UUID string of spec), lines (array non-empty, each {materialUuid: UUID string, quantityPerUnit: bcmath string e.g. \"2.500\", sort?: int}).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/inventory/recipes \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"specificationUuid\":\"550e8400-e29b-41d4-a716-446655440001\",\"lines\":[{\"materialUuid\":\"550e8400-e29b-41d4-a716-446655440002\",\"quantityPerUnit\":\"10.000\",\"sort\":1},{\"materialUuid\":\"550e8400-e29b-41d4-a716-446655440003\",\"quantityPerUnit\":\"2.500\"}]}'\n→ 201 {data:{id,specificationUuid}}\nPayload example: {\"specificationUuid\":\"550e8400-...\",\"lines\":[{\"materialUuid\":\"550e8400-...\",\"quantityPerUnit\":\"2.500\"}]}\nErrors: 400 Missing spec/lines or invalid UUID; 409 Duplicate active recipe for spec."]],
        '/api/v1/manage/promotions' => ['summary' => ['get' => 'List promotions (admin)', 'post' => 'Create promotion (admin)'], 'desc' => ['get' => "Paginated admin promotion list (all, including inactive). Supports @filter, @order, @select, @expands. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/promotions?page=1&limit=20&@order=startTime|DESC → {data:[{id,name,template:{id},storeCode,enabled,startTime,endTime,config}], paginator}\nFilter by enabled: GET /api/v1/manage/promotions?@filter=entity.enabled==true\nFilter by storeCode: GET /api/v1/manage/promotions?@filter=entity.storeCode==\"XUHUI\"\nApp endpoint is filtered to active+time-window; manage is unfiltered.", 'post' => "Create promotion (admin). Required: name, template (int template id). Optional: description, storeCode (string, store scope nullable), enabled (bool default true), startTime/endTime (datetime ISO8601 nullable), config (object, template-specific, e.g. {discount:1000}), conflictMode (string). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/promotions \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"Double 11 Sale\",\"template\":1,\"storeCode\":\"XUHUI\",\"enabled\":true,\"startTime\":\"2026-11-11T00:00:00+00:00\",\"endTime\":\"2026-11-11T23:59:59+00:00\",\"config\":{\"discount\":1000}}'\n→ 201 {data:{id,name}}\nPayload example: {\"name\":\"Double 11 Sale\",\"template\":1,\"enabled\":true,\"config\":{\"discount\":1000}}"]],
        '/api/v1/manage/promotions/{id}' => ['summary' => ['get' => 'Get promotion detail (admin)', 'put' => 'Update promotion (admin)', 'delete' => 'Delete promotion (admin)'], 'desc' => ['get' => "Get promotion by id (admin). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/promotions/1 → {data:{id,name,description,template:{id},storeCode,enabled,startTime,endTime,config}}", 'put' => "Update promotion: name, description, template, storeCode, enabled, startTime, endTime, config, conflictMode. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/promotions/1 -H \"Authorization: Bearer <admin_jwt>\" -d '{\"name\":\"Updated Sale\",\"enabled\":false}' → 200", 'delete' => "Delete promotion (admin). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/promotions/1 → 200"]],
        '/api/v1/manage/promotions/batch-update' => ['summary' => ['post' => 'Batch update promotions (admin)'], 'desc' => ['post' => "Batch upsert promotions (admin). Query: @mode=mixed|strict|create, @basis=id, @partial bool.\nBody: JSON array of promotion objects.\nHeader: Authorization: Bearer <admin_jwt>\nExample: POST /api/v1/manage/promotions/batch-update?@mode=mixed&@basis=id -d '[{\"id\":1,\"enabled\":false}]' → 200"]],
        '/api/v1/manage/promotion-templates' => ['summary' => ['get' => 'List promotion templates (admin)', 'post' => 'Create promotion template (admin)'], 'desc' => ['get' => "Paginated admin promotion template list. Supports @filter, @order, @select. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/promotion-templates?page=1&limit=20 → {data:[{id,name,type,phase,enabled,dsl}], paginator}\nFilter enabled: GET /api/v1/manage/promotion-templates?@filter=entity.enabled==true", 'post' => "Create promotion template (admin). Required: name, type (string e.g. discount), dsl (string DSL code, e.g. \"if order.total > 10000 then discount 1000\"). Optional: description, phase (string e.g. pre_price), enabled (bool), fields (object). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/promotion-templates \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"Buy 2 Get 1\",\"type\":\"discount\",\"phase\":\"pre_price\",\"enabled\":true,\"dsl\":\"if order.total > 10000 then discount 1000\"}'\n→ 201 {data:{id,name}}"]],
        '/api/v1/manage/promotion-templates/batch-update' => ['summary' => ['post' => 'Batch update promotion templates (admin)'], 'desc' => ['post' => "Batch upsert promotion templates (admin). Query: @mode=mixed|strict|create, @basis=id, @partial.\nBody: JSON array of template objects.\nHeader: Authorization: Bearer <admin_jwt>\nExample: POST /api/v1/manage/promotion-templates/batch-update?@mode=mixed&@basis=id -d '[{\"id\":1,\"dsl\":\"new dsl\"}]' → 200"]],
        '/api/v1/manage/promotion-templates/{id}' => ['summary' => ['get' => 'Get promotion template detail (admin)', 'put' => 'Update promotion template (admin)', 'delete' => 'Delete promotion template (admin)'], 'desc' => ['get' => "Get promotion template by id (admin). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/promotion-templates/1 → {data:{id,name,type,dsl,enabled}}", 'put' => "Update template: name, description, type, phase, enabled, dsl, fields. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X PUT /api/v1/manage/promotion-templates/1 -H \"Authorization: Bearer <admin_jwt>\" -d '{\"dsl\":\"if order.total > 20000 then discount 2000\"}' → 200", 'delete' => "Delete promotion template (admin). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: DELETE /api/v1/manage/promotion-templates/1 → 200"]],
        '/api/v1/manage/promotion-templates/{id}/validate' => ['summary' => ['post' => 'Validate promotion template DSL (admin)'], 'desc' => ['post' => "Lexes/parses DSL for stored template {id}, returns AST or errors. ROLE_ADMIN. No body required (empty {}).\nHeader: Authorization: Bearer <admin_jwt>\nExample: POST /api/v1/manage/promotion-templates/1/validate -d '{}' → 200 {data:{ast:{type:\"program\"}}} or 422 {errors:[{message,line}]}\nCurl: curl -X POST /api/v1/manage/promotion-templates/1/validate -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" -d '{}'\nErrors: 404 Template not found; 422 DSL syntax error."]],
        '/api/v1/manage/promotion-templates/{id}/dry-run' => ['summary' => ['post' => 'Dry-run promotion template DSL (admin)'], 'desc' => ['post' => "Evaluates DSL against sample order total and meta without persisting. ROLE_ADMIN. Optional body: order (object, sample order e.g. {total:19900,currency:\"CNY\"}), meta (object).\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/promotion-templates/1/dry-run \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"order\":{\"total\":19900,\"currency\":\"CNY\",\"items\":[{\"specificationId\":1,\"quantity\":1}]},\"meta\":{}}'\n→ 200 {data:{result:{discount:1000,applied:true}}}\nPayload example: {\"order\":{\"total\":19900},\"meta\":{}}\nErrors: 404 Template not found."]],
        '/api/v1/manage/settlement-rules' => ['summary' => ['get' => 'List settlement rules (admin)', 'post' => 'Create settlement rule (admin)'], 'desc' => ['get' => "Paginated admin settlement rule list. Supports @filter, @order. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/settlement-rules?page=1&limit=20 → {data:[{id,uuid,code,name,status}], paginator}\nFilter by code: GET /api/v1/manage/settlement-rules?@filter=entity.code==\"PLATFORM_FEE\"", 'post' => "Create settlement rule. ROLE_ADMIN. Required: code (string unique), name (string).\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X POST /api/v1/manage/settlement-rules -H \"Authorization: Bearer <admin_jwt>\" -d '{\"code\":\"PLATFORM_FEE\",\"name\":\"Platform Fee 5%\"}' → 201"]],
        '/api/v1/manage/settlement-rules/{id}' => ['summary' => ['get' => 'Get settlement rule detail (admin)'], 'desc' => ['get' => "Get settlement rule by id/uuid (admin). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/settlement-rules/1 → {data:{id,uuid,code,name}}"]],
        '/api/v1/manage/settlement-rule-versions' => ['summary' => ['get' => 'List settlement rule versions (admin)', 'post' => 'Create settlement rule version (admin)'], 'desc' => ['get' => "Paginated admin rule version list. Supports @filter. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/settlement-rule-versions?page=1&limit=20 → {data:[{id,uuid,ruleUuid,priority,status}], paginator}", 'post' => "Create settlement rule version. ROLE_ADMIN. Required: ruleUuid (UUID), definition (object), priority (int), effectiveFrom (datetime). Optional: effectiveTo.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X POST /api/v1/manage/settlement-rule-versions -H \"Authorization: Bearer <admin_jwt>\" -d '{\"ruleUuid\":\"550e8400-e29b-41d4-a716-446655440000\",\"definition\":{\"type\":\"percentage\"},\"priority\":10,\"effectiveFrom\":\"2026-01-01T00:00:00+00:00\"}' → 201"]],
        '/api/v1/manage/settlement-rule-versions/{id}' => ['summary' => ['get' => 'Get settlement rule version detail (admin)', 'put' => 'Update settlement rule version (draft only)', 'delete' => 'Delete settlement rule version (admin)'], 'desc' => ['get' => "Get rule version by id (admin). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/settlement-rule-versions/1 → {data:{id,uuid,definition}}", 'put' => "Update draft rule version: definition, priority, effectiveFrom, effectiveTo. ROLE_ADMIN. Published versions immutable (400).", 'delete' => "Delete rule version (admin, draft only). ROLE_ADMIN."]],
        '/api/v1/manage/settlement-plans' => ['summary' => ['get' => 'List settlement plans (admin)'], 'desc' => ['get' => "Paginated admin settlement plan list. Supports @filter. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/settlement-plans?page=1&limit=20 → {data:[{uuid,status}], paginator}"]],
        '/api/v1/manage/settlement-plans/{id}' => ['summary' => ['get' => 'Get settlement plan detail (admin)'], 'desc' => ['get' => "Get settlement plan by uuid (admin). ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/settlement-plans/<uuid> → {data:{uuid,status,allocations}}"]],
        '/api/v1/manage/settlement-rules/configuration' => ['summary' => ['get' => 'Get settlement rules configuration (admin)'], 'desc' => ['get' => "Returns settlement rule schema/configuration (fields, types, constraints) for building rule definitions. ROLE_ADMIN.\nHeader: Authorization: Bearer <admin_jwt>\nExample: GET /api/v1/manage/settlement-rules/configuration → {data:{schema:{fields:[{name, type}]}}}\nCurl: curl -H \"Authorization: Bearer <admin_jwt>\" /api/v1/manage/settlement-rules/configuration"]],
        '/api/v1/manage/settlement-rule-versions/{uuid}/publish' => ['summary' => ['post' => 'Publish settlement rule version (admin)'], 'desc' => ['post' => "Transitions settlement rule version {uuid} from draft → published. ROLE_ADMIN. No body required.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X POST /api/v1/manage/settlement-rule-versions/<uuid>/publish -H \"Authorization: Bearer <admin_jwt>\" -d '{}' → 200 {data:{status:\"published\"}}\nErrors: 400 Already published / invalid state; 404 Version or Rule not found."]],
        '/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/post' => ['summary' => ['post' => 'Post settlement allocation (admin)'], 'desc' => ['post' => "Post allocation {allocationUuid} of plan {uuid}. Moves allocation status to posted and emits ledger entries. ROLE_ADMIN. No body required.\nHeader: Authorization: Bearer <admin_jwt>\nCurl: curl -X POST /api/v1/manage/settlement-plans/<uuid>/allocations/<allocationUuid>/post -H \"Authorization: Bearer <admin_jwt>\" -d '{}' → 200 {code:0}\nErrors: 400 Already posted / invalid state; 404 Plan/allocation not found."]],
        '/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/reverse' => ['summary' => ['post' => 'Reverse settlement allocation (admin)'], 'desc' => ['post' => "Reverse posted allocation. ROLE_ADMIN. Required: reversalId (string, idempotency), reasonCode (string), reasonDetail (string). Generates reversal entry.\nHeader: Authorization: Bearer <admin_jwt>\nCurl:\ncurl -X POST /api/v1/manage/settlement-plans/<uuid>/allocations/<allocationUuid>/reverse \\\n  -H \"Authorization: Bearer <admin_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"reversalId\":\"rev-001\",\"reasonCode\":\"error\",\"reasonDetail\":\"Incorrect amount\",\"reason\":\"Manual reversal\"}'\n→ 200 {code:0}\nPayload example: {\"reversalId\":\"rev-001\",\"reasonCode\":\"error\",\"reasonDetail\":\"Detail\",\"reason\":\"Reversal\"}\nErrors: 400 Not posted / already reversed; 404 Not found."]],

        // --- Store: staff scoped products ---
        '/api/v1/store/{scopeId}/products' => ['tag' => 'Store', 'summary' => ['get' => 'List scoped store products (staff)', 'post' => 'Create scoped store product (staff)'], 'desc' => ['get' => "Staff scoped product list via store uuid (scopeId, the Store UUID). Paginated. Supports @filter, @dql, @order, @select, @sort, @expands, @display. Requires store membership (store:product:read) via store scopeId. Auth: Bearer JWT, ROLE_USER.\nHeader: Authorization: Bearer <staff_jwt>\nExample: GET /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products?page=1&limit=20&@order=name|ASC\n→ {data:[{id,uuid,name,description,status,store:{uuid},metadata,isDeleted}], paginator}\nFilter active: GET /api/v1/store/{scopeId}/products?@filter=entity.status==\"active\"\nPath param scopeId is the Store UUID (store scopeId). Returns only products where product.store = scopeId and isDeleted=false. Global products (store null) are NOT returned here; use /app/products with X-Store-Code for merged view.", 'post' => "Create product bound to store scopeId. Requires store:product:create. Body: name required, description optional, status in [active,inactive] (default active), metadata (object). Store is auto-bound from scopeId — do NOT send store field.\nHeader: Authorization: Bearer <staff_jwt>\nCurl:\ncurl -X POST /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products \\\n  -H \"Authorization: Bearer <staff_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"iPhone 15 Pro\",\"description\":\"The latest iPhone\",\"status\":\"active\",\"metadata\":{\"brand\":\"Apple\"}}'\n→ 201 {data:{id,uuid,name,status,store:{uuid:\"550e8400-...\"}}}\nPayload example: {\"name\":\"iPhone 15 Pro\",\"description\":\"The latest iPhone\",\"status\":\"active\",\"metadata\":{\"brand\":\"Apple\"}}\nNote: store scopeId in path determines the owning store; product will be visible to /app/products with X-Store-Code matching that store."]],
        '/api/v1/store/{scopeId}/products/batch-update' => ['tag' => 'Store', 'summary' => ['post' => 'Batch update/upsert scoped store products (staff)'], 'desc' => ['post' => "Batch upsert for store scopeId products. Query: @mode=mixed|strict|create, @basis=id,uuid,name, @partial bool, @transform JSON. Body: array of product objects (each may contain id/uuid as basis). Store scopeId in path binds the scope — objects without explicit store are assumed within that store.\nHeader: Authorization: Bearer <staff_jwt>\nExample:\ncurl -X POST \"/api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <staff_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":1,\"name\":\"iPhone 15 Pro Max\",\"status\":\"active\"},{\"name\":\"New Store Product\",\"description\":\"Fresh\",\"status\":\"active\"}]'\n→ 200 [{id:1,...},{id:2,...}]\nRequires store:product:update|create. Payload example: [{\"id\":1,\"name\":\"Updated\"},{\"name\":\"New Store Product\"}]" ]],
        '/api/v1/store/{scopeId}/products/{id}' => ['tag' => 'Store', 'summary' => ['get' => 'Get scoped store product detail (staff)', 'put' => 'Update scoped store product (staff)', 'delete' => 'Delete scoped store product (staff)'], 'desc' => ['get' => "Detail by id within scopeId store. Path params: scopeId (store UUID, store scopeId), id (product id|uuid). Requires store:product:read.\nHeader: Authorization: Bearer <staff_jwt>\nExample: GET /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/1 → {data:{id,uuid,name,description,status,store:{uuid},metadata}}\nSupports @expands=specifications. 404 if product not in store scope or isDeleted.", 'put' => "Update fields: name, description, status in [active,inactive], metadata within scopeId. Requires store:product:update.\nHeader: Authorization: Bearer <staff_jwt>\nCurl: curl -X PUT /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/1 -H \"Authorization: Bearer <staff_jwt>\" -H \"Content-Type: application/json\" -d '{\"name\":\"iPhone 15 Pro Max\",\"status\":\"active\"}' → 200 {data:{id,name}}\nPayload example: {\"name\":\"iPhone 15 Pro Max\",\"description\":\"Updated\",\"status\":\"active\",\"metadata\":{\"brand\":\"Apple\"}}", 'delete' => "Soft delete (isDeleted=true) within scopeId. Returns 204 (empty). Requires store:product:delete.\nHeader: Authorization: Bearer <staff_jwt>\nExample: DELETE /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/1 → 204\nCurl: curl -X DELETE -H \"Authorization: Bearer <staff_jwt>\" /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/1\nNote: orphanRemoval cascades specifications; product not hard-deleted."]],
        '/api/v1/store/{scopeId}/products/{productUuid}/specifications' => ['tag' => 'Store', 'summary' => ['get' => 'List scoped specifications for product (staff)', 'post' => 'Create scoped specification (SKU) (staff)'], 'desc' => ['get' => "List specifications filtered by productUuid within store scopeId. Paginated. Requires store:specification:read. Path params: scopeId (store UUID, store scopeId), productUuid (product UUID).\nHeader: Authorization: Bearer <staff_jwt>\nExample: GET /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/550e8400-e29b-41d4-a716-446655440001/specifications?page=1\n→ {data:[{id,uuid,name,price,status,sort,product:{uuid}}], paginator}\n404 if product not in store scope. Price in cents (e.g. 699900 = ¥6999.00).", 'post' => "Create specification (SKU) under productUuid in store scopeId. Requires store:specification:create. Body: name required, price in cents (int >=0, e.g. 699900 = ¥6999.00) required, status in [active,inactive] (default active), sort (int).\nHeader: Authorization: Bearer <staff_jwt>\nCurl:\ncurl -X POST /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/550e8400-e29b-41d4-a716-446655440001/specifications \\\n  -H \"Authorization: Bearer <staff_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"128GB Silver\",\"price\":699900,\"status\":\"active\",\"sort\":1}'\n→ 201 {data:{id,uuid,name,price,status}}\nPayload example: {\"name\":\"128GB Silver\",\"price\":699900,\"status\":\"active\",\"sort\":1}"]],
        '/api/v1/store/{scopeId}/products/{productUuid}/specifications/batch-update' => ['tag' => 'Store', 'summary' => ['post' => 'Batch update/upsert scoped specifications (staff)'], 'desc' => ['post' => "Batch upsert specifications for productUuid in store scopeId. Query: @mode=mixed|strict|create, @basis=id,uuid, @partial bool. Body: array of spec objects (each may contain id/uuid, name, price in cents, status, sort).\nHeader: Authorization: Bearer <staff_jwt>\nExample:\ncurl -X POST \"/api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/550e8400-e29b-41d4-a716-446655440001/specifications/batch-update?@mode=mixed&@basis=id\" \\\n  -H \"Authorization: Bearer <staff_jwt>\" -H \"Content-Type: application/json\" \\\n  -d '[{\"id\":10,\"price\":799900},{\"name\":\"256GB Black\",\"price\":899900,\"sort\":2}]'\n→ 200 [{...}]\nRequires store:specification:update|create. Payload example: [{\"id\":10,\"price\":799900},{\"name\":\"256GB Black\",\"price\":899900}]" ]],
        '/api/v1/store/{scopeId}/products/{productUuid}/specifications/{id}' => ['tag' => 'Store', 'summary' => ['get' => 'Get scoped specification detail (staff)', 'put' => 'Update scoped specification (staff)', 'delete' => 'Delete scoped specification (staff)'], 'desc' => ['get' => "Detail by id within productUuid + scopeId. Path params: scopeId (store UUID, store scopeId), productUuid (product UUID), id (spec id|uuid). Requires store:specification:read.\nHeader: Authorization: Bearer <staff_jwt>\nExample: GET /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/550e8400-e29b-41d4-a716-446655440001/specifications/10 → {data:{id,uuid,name,price,status,sort}}\nPrice in cents: 699900 = ¥6999.00. 404 if not in scope.", 'put' => "Update name, price in cents (>=0), status in [active,inactive], sort within scopeId/product. Requires store:specification:update.\nHeader: Authorization: Bearer <staff_jwt>\nCurl: curl -X PUT /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/550e8400-e29b-41d4-a716-446655440001/specifications/10 -H \"Authorization: Bearer <staff_jwt>\" -H \"Content-Type: application/json\" -d '{\"name\":\"256GB Deep Black\",\"price\":899900,\"status\":\"active\"}' → 200 {data:{id,name,price}}\nPayload example: {\"name\":\"256GB Deep Black\",\"price\":899900,\"status\":\"active\",\"sort\":2}", 'delete' => "Soft delete (isDeleted=true) within scopeId/product. Requires store:specification:delete.\nHeader: Authorization: Bearer <staff_jwt>\nExample: DELETE /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/550e8400-e29b-41d4-a716-446655440001/specifications/10 → 204\nCurl: curl -X DELETE -H \"Authorization: Bearer <staff_jwt>\" /api/v1/store/550e8400-e29b-41d4-a716-446655440000/products/550e8400-e29b-41d4-a716-446655440001/specifications/10"]],
        '/api/v1/store/{scopeId}/orders/{id}' => ['tag' => 'Store', 'summary' => ['get' => 'Get scoped store order detail'], 'desc' => ['get' => 'Staff scoped order detail via store uuid (scopeId) + order id/uuid. Requires store membership (store:order:read).']],

        // --- Authorization: assignments / roles / permissions / audit-logs ---
        '/api/v1/manage/assignments' => ['tag' => 'Authorization', 'summary' => ['get' => 'List assignments (grants)', 'post' => 'Create assignment (grant role)'], 'desc' => ['get' => 'Paginated. Filters: userUuid, scopeType=global|store, scopeUuid, includeRevoked bool, roleId. ROLE_ADMIN. Requires bearer JWT.', 'post' => 'Grant role to user. Body: userUuid (UUID) required, roleUuid|role_uuid|roleId required (UUID|id|code), scopeType=global|store required, scopeUuid (UUID, null for global, required for store). Example: {"userUuid":"...","roleUuid":"...","scopeType":"store","scopeUuid":"..."}. ROLE_ADMIN. Audited + cache invalidated.']],
        '/api/v1/manage/assignments/batch-update' => ['tag' => 'Authorization', 'summary' => ['post' => 'Batch update/upsert assignments'], 'desc' => ['post' => 'Batch upsert assignments. Query: @mode, @basis, @partial. Body: array of assignment objects. ROLE_ADMIN.']],
        '/api/v1/manage/assignments/{id}' => ['tag' => 'Authorization', 'summary' => ['get' => 'Get assignment detail', 'put' => 'Update assignment', 'delete' => 'Revoke assignment'], 'desc' => ['get' => 'Get assignment by id/uuid. ROLE_ADMIN.', 'put' => 'Update userUuid, roleUuid, scopeType, scopeUuid. Validates role scope compatibility, uniqueness. Audited. ROLE_ADMIN.', 'delete' => 'Soft revoke (sets revokedAt). Already revoked → 204. Audited + cache invalidated. ROLE_ADMIN.']],
        '/api/v1/manage/roles' => ['tag' => 'Authorization', 'summary' => ['get' => 'List roles', 'post' => 'Create role'], 'desc' => ['get' => 'Paginated role list. ROLE_ADMIN.', 'post' => 'Create non-system role. Body: code required [a-z0-9_], name required, scopeType=global|store required, uuid optional. Example: {"code":"store_manager","name":"Store Manager","scopeType":"store"}. ROLE_ADMIN. Audited.']],
        '/api/v1/manage/roles/batch-update' => ['tag' => 'Authorization', 'summary' => ['post' => 'Batch update/upsert roles'], 'desc' => ['post' => 'Batch upsert roles. ROLE_ADMIN. Query: @mode, @basis, @partial.']],
        '/api/v1/manage/roles/{id}' => ['tag' => 'Authorization', 'summary' => ['get' => 'Get role detail', 'put' => 'Update role', 'delete' => 'Delete role'], 'desc' => ['get' => 'Get role by id/uuid. ROLE_ADMIN.', 'put' => 'Update code [a-z0-9_] and name. System roles cannot be modified. ROLE_ADMIN. Cache invalidated for assigned users.', 'delete' => 'Delete non-system role. System → 403. ROLE_ADMIN.']],
        '/api/v1/manage/roles/{uuid}/permissions' => ['tag' => 'Authorization', 'summary' => ['post' => 'Replace role permissions'], 'desc' => ['post' => 'Replace all permissions for role uuid. Body: permissions|!codes|array of codes [a-z0-9:_] required. Example: {"permissions":["store:product:read","store:order:accept"]}. Validates existence. System role → 403. Audited + cache invalidated. ROLE_ADMIN. Path params: uuid (role UUID).']],
        '/api/v1/manage/roles/{uuid}/field-grants/{resource}/{action}' => ['tag' => 'Authorization', 'summary' => ['put' => 'Replace role field grant'], 'desc' => ['put' => 'Create or update field grant for role uuid + resource + action. Body: fields|array of field names required (array of strings, unique). Validates via AuthorizationResourceRegistry. System role → 403. Audited + cache invalidated. ROLE_ADMIN. Path params: uuid, resource, action. Example: {"fields":["name","price"]}.']],
        '/api/v1/manage/permissions' => ['tag' => 'Authorization', 'summary' => ['get' => 'List permissions'], 'desc' => ['get' => 'Paginated list of registered permissions (code, module, resource, action). ROLE_ADMIN. Read-only.']],
        '/api/v1/manage/permissions/{id}' => ['tag' => 'Authorization', 'summary' => ['get' => 'Get permission detail'], 'desc' => ['get' => 'Get permission by id. ROLE_ADMIN.']],
        '/api/v1/manage/audit-logs' => ['tag' => 'Authorization', 'summary' => ['get' => 'List audit logs'], 'desc' => ['get' => 'Paginated audit trail for authorization changes. Filters: targetType, actorUuid. ROLE_ADMIN.']],
        '/api/v1/manage/audit-logs/{id}' => ['tag' => 'Authorization', 'summary' => ['get' => 'Get audit log detail'], 'desc' => ['get' => 'Get audit log entry by id. ROLE_ADMIN.']],
        '/api/auth/register' => ['tag' => 'Auth', 'summary' => ['post' => 'Register — email/username/password (+ optional phone)'], 'desc' => ['post' => 'Public. No Auth header. Create user account. Password strength enforced (8+ chars, upper+lower+digit/special). Returns RS256 JWT access_token (7200s) + refresh_token (1yr) + expires_in.
Header: Content-Type: application/json (no Authorization)
Curl:
curl -X POST /api/auth/register -H "Content-Type: application/json" -d \'{"email":"user@example.com","username":"newuser","password":"P@ssw0rd","phone":"+8613912345678"}\'
→ 201 {"access_token":"eyJ...","expires_in":7200,"refresh_token":"eyJ..."}
Example payload (minimal): {"email":"user@example.com","username":"newuser","password":"P@ssw0rd"}
Example payload (with phone): {"email":"user@example.com","username":"newuser","password":"P@ssw0rd","phone":"+8613912345678"}
Success: 201 + tokens. Auto-creates linked Profile (level bronze).
Errors: 400 Missing fields / weak password / invalid email or phone format; 409 Email/username/phone already exists; 422 Validation. No auth required.']],
        '/api/auth/login' => ['tag' => 'Auth', 'summary' => ['post' => 'Login — identifier + password → JWT'], 'desc' => ['post' => 'Public. No Auth header. Authenticate with email, username, or verified phone + password. Returns RS256 JWT access_token (7200s) and refresh_token (1yr) + expires_in.
Header: Content-Type: application/json (no Authorization). Use returned access_token as Authorization: Bearer <token> for subsequent calls.
Curl:
curl -X POST /api/auth/login -H "Content-Type: application/json" -d \'{"identifier":"admin@example.com","password":"P@ssw0rd"}\'
→ 200 {"access_token":"eyJ...","expires_in":7200,"refresh_token":"eyJ..."}
Example payload (email): {"identifier":"admin@example.com","password":"P@ssw0rd"}
Example payload (username): {"identifier":"newuser","password":"P@ssw0rd"}
Example payload (phone): {"identifier":"+8613912345678","password":"P@ssw0rd"}  // phone must be verified, else 403
Errors: 400 Identifier or password missing / empty; 401 Invalid credentials (user not found or password mismatch); 403 Phone not verified (phone identifier but phoneVerified=false); 429 Rate-limit if brute force.']],
        '/api/auth/otp/request' => ['tag' => 'Auth', 'summary' => ['post' => 'Request OTP via SMS'], 'desc' => ['post' => 'Public. No Auth header. Sends 6-digit OTP via Alibaba Cloud SMS. Rate-limit 60s per phone+purpose. Dry-run in dev (logs code, no SMS).
Header: Content-Type: application/json
Curl:
curl -X POST /api/auth/otp/request -H "Content-Type: application/json" -d \'{"phone":"+8613912345678","purpose":"login"}\'
→ 204 No Content (empty body)
Example payload (login): {"phone":"+8613912345678","purpose":"login"}
Example payload (verify_phone): {"phone":"+8613912345678","purpose":"verify_phone"}
Purpose enum: login | verify_phone (default login). Template selected by purpose.
Success: 204. OTP valid 5 min, max 5 verify attempts then invalidated.
Errors: 400 Phone required / invalid purpose (must be login or verify_phone); 429 Too many requests / 60s cooldown or max attempts exceeded.']],
        '/api/auth/otp/verify' => ['tag' => 'Auth', 'summary' => ['post' => 'Verify OTP code → tokens or phone_verified'], 'desc' => ['post' => 'Public. No Auth header. Verifies 6-digit code. Branch by purpose.
Header: Content-Type: application/json
Curl (login):
curl -X POST /api/auth/otp/verify -H "Content-Type: application/json" -d \'{"phone":"+8613912345678","otp":"123456","purpose":"login"}\'
→ 200 {"access_token":"eyJ...","expires_in":7200,"refresh_token":"eyJ..."}
Curl (verify_phone):
curl -X POST /api/auth/otp/verify -H "Content-Type: application/json" -d \'{"phone":"+8613912345678","otp":"123456","purpose":"verify_phone"}\'
→ 200 {"phone_verified":true}
Example payload (login): {"phone":"+8613912345678","otp":"123456","purpose":"login"}
Example payload (verify_phone): {"phone":"+8613912345678","otp":"654321","purpose":"verify_phone"}
Success: login→200+tokens (requires user exists + phoneVerified); verify_phone→200 {phone_verified:true} + sets User.phoneVerified=true.
Errors: 400 Phone/OTP missing or invalid purpose; 401 Invalid or expired OTP (wrong code, expired 5 min, max 5 attempts); 401 Phone not verified or user not found (login branch).']],
        '/api/auth/token/refresh' => ['tag' => 'Auth', 'summary' => ['post' => 'Refresh access token (rotate refresh)'], 'desc' => ['post' => 'Public. No Auth header (uses refresh_token in body). Rotates refresh token; reuse detection revokes ALL user tokens.
Header: Content-Type: application/json
Curl:
curl -X POST /api/auth/token/refresh -H "Content-Type: application/json" -d \'{"refresh_token":"eyJ..."}\'
→ 200 {"access_token":"eyJ...","expires_in":7200,"refresh_token":"eyJ..."}
Example payload: {"refresh_token":"eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9..."}
Success: 200 + new pair. Old refresh_token invalidated; access_token TTL 7200s, refresh 1yr.
Errors: 400 Refresh token missing / empty; 401 Invalid, expired, or reused refresh token (reuse → revoke all tokens for user, force re-login).']],
        '/api/auth/logout' => ['tag' => 'Auth', 'summary' => ['post' => 'Logout — revoke tokens'], 'desc' => ['post' => 'Authenticated or public with token in body/header. Revokes provided access_token and/or refresh_token. If access_token omitted, tries Authorization: Bearer <token> header.
Header: Authorization: Bearer <access_token> (optional, can also send tokens in body)
Curl (header):
curl -X POST /api/auth/logout -H "Authorization: Bearer <access_token>" -H "Content-Type: application/json" -d \'{"refresh_token":"eyJ..."}\'
→ 204 No Content
Curl (body only):
curl -X POST /api/auth/logout -H "Content-Type: application/json" -d \'{"access_token":"eyJ...","refresh_token":"eyJ..."}\'
→ 204
Example payload (both): {"access_token":"eyJ...","refresh_token":"eyJ..."}
Example payload (refresh only): {"refresh_token":"eyJ..."}
Success: 204 regardless of token validity (idempotent).
Errors: 400 Invalid JSON format (rare). No auth required to call, but token must be supplied to revoke.']],
        '/api/wechat/miniapp/login' => ['tag' => 'Wechat', 'summary' => ['post' => 'WeChat Mini Program login — js_code → JWT'], 'desc' => ['post' => 'Public. No Auth header. Exchange WeChat Mini Program js_code (wx.login) for openid/unionid, create or find local User, return JWT access+refresh tokens.
Header: Content-Type: application/json
Curl:
curl -X POST /api/wechat/miniapp/login -H "Content-Type: application/json" -d \'{"js_code":"081abc..."}\'
→ 200 {"access_token":"eyJ...","expires_in":7200,"refresh_token":"eyJ..."}
Example payload: {"js_code":"081abc...wx.code.from.wx.login"}
Success: 200 + tokens. Creates User + WechatUser binding if new openid.
Errors: 400 js_code missing / empty; 401 WeChat API error (invalid code, expired, appId/secret mismatch, network).']],
        '/api/wechat/miniapp/phone' => ['tag' => 'Wechat', 'summary' => ['post' => 'Bind WeChat Mini Program phone — code → verified phone'], 'desc' => ['post' => 'Authenticated. Requires Authorization: Bearer <jwt>. Exchange WeChat getPhoneNumber code for the current user\'s phone number and mark it verified.
Header: Authorization: Bearer <user_jwt> + Content-Type: application/json
Curl:
curl -X POST /api/wechat/miniapp/phone -H "Authorization: Bearer <user_jwt>" -H "Content-Type: application/json" -d \'{"code":"xyz...getPhoneNumber.code"}\'
→ 204 No Content
Example payload: {"code":"xyz..."}  // code from wx.getPhoneNumber event.detail.code
Success: 204; User.phone updated + phoneVerified=true; WechatUser phone updated.
Errors: 400 code missing / empty or WeChat API error (invalid code, expired); 401 Not authenticated / invalid JWT or missing Authorization header.']],
        '/wechat/miniapp/phone' => ['tag' => 'Wechat', 'summary' => ['post' => 'Bind WeChat Mini Program phone (alias)'], 'desc' => ['post' => 'Alias of /api/wechat/miniapp/phone. Authenticated. Requires Authorization: Bearer <jwt>. Exchange getPhoneNumber code for verified phone.
Header: Authorization: Bearer <user_jwt>
Example payload: {"code":"xyz..."} → 204
Errors: 400 code missing / WeChat API error; 401 Not authenticated. See /api/wechat/miniapp/phone for full details.']],
        '/api/wechat/oauth/url' => ['tag' => 'Wechat', 'summary' => ['get' => 'WeChat OA — OAuth redirect URL'], 'desc' => ['get' => 'Public. No Auth header. Returns WeChat Official Account OAuth authorization URL for snsapi_userinfo scope.
Query: ?redirect_uri=https://example.com/wechat/callback (required, must be URL-encoded and whitelisted in WeChat config)
Header: None
Curl:
curl "https://api.example.com/api/wechat/oauth/url?redirect_uri=https%3A%2F%2Fexample.com%2Fwechat%2Fcallback"
→ 200 {"url":"https://open.weixin.qq.com/connect/oauth2/authorize?appid=...&redirect_uri=...&response_type=code&scope=snsapi_userinfo"}
Example: GET /api/wechat/oauth/url?redirect_uri=https://example.com/callback
Success: 200 {url}
Errors: 400 redirect_uri missing / empty.']],
        '/api/wechat/oauth/callback' => ['tag' => 'Wechat', 'summary' => ['post' => 'WeChat OA — OAuth callback code → JWT'], 'desc' => ['post' => 'Public. No Auth header. Exchanges WeChat OAuth code (from authorize redirect) for user info (openid, nickname, avatar), creates or finds user, returns JWT tokens.
Header: Content-Type: application/json
Curl:
curl -X POST /api/wechat/oauth/callback -H "Content-Type: application/json" -d \'{"code":"081abc..."}\'
→ 200 {"access_token":"eyJ...","expires_in":7200,"refresh_token":"eyJ..."}
Example payload: {"code":"081abc...oauth.code"}
Success: 200 + tokens. Creates User+WechatUser if new openid.
Errors: 400 code missing / empty; 401 WeChat OAuth error (invalid/expired code, appId mismatch).']],
        '/wechat/oauth/url' => ['tag' => 'Wechat', 'summary' => ['get' => 'WeChat OA — OAuth redirect URL (alias)'], 'desc' => ['get' => 'Alias of /api/wechat/oauth/url. Public. GET /wechat/oauth/url?redirect_uri=... → 200 {url}
Errors: 400 redirect_uri missing. See /api/wechat/oauth/url.']],
        '/wechat/oauth/callback' => ['tag' => 'Wechat', 'summary' => ['post' => 'WeChat OA — OAuth callback (alias)'], 'desc' => ['post' => 'Alias of /api/wechat/oauth/callback. Public. POST {"code":"..."} → 200 {access_token,refresh_token}
Errors: 400 code missing; 401 WeChat error.']],
        '/api/v1/app/users/me' => ['tag' => 'Auth', 'summary' => ['get' => 'Get current user profile (me)', 'put' => 'Update current user profile (me)'], 'desc' => ['get' => 'Authenticated. Requires Authorization: Bearer <user_jwt>. Returns current User entity (id, uuid, email, username, phone, phoneVerified, roles, createdAt).
Header: Authorization: Bearer <user_jwt>
Curl:
curl -H "Authorization: Bearer <user_jwt>" /api/v1/app/users/me
→ 200 {data:{id,uuid,email,username,phone,phoneVerified,roles}}
Example response: {data:{id:1,uuid:"...",email:"user@example.com",username:"newuser",phone:"+8613912345678",phoneVerified:true}}
Errors: 401 Not authenticated / invalid JWT; 403 Forbidden if missing ROLE_USER.', 'put' => 'Authenticated. Requires Authorization: Bearer <user_jwt>. Update own profile (email, username, phone, password).
Header: Authorization: Bearer <user_jwt> + Content-Type: application/json
Curl:
curl -X PUT /api/v1/app/users/me -H "Authorization: Bearer <user_jwt>" -H "Content-Type: application/json" -d \'{"username":"newname","email":"new@example.com"}\'
→ 200 {data:{id,username,email}}
Example payload (email): {"email":"new@example.com"}
Example payload (username): {"username":"newname"}
Example payload (phone): {"phone":"+8613912345678"}
Example payload (password): {"password":"NewP@ssw0rd"}  // hashed server-side
All fields optional; only supplied fields updated via UserService::updateProfile.
Success: 200 + updated user.
Errors: 400 Validation (invalid email format, duplicate email/username/phone → 409 conflict via service), 401 Not authenticated, 403 Missing role.']],
        '/api/v1/app/users/change-password' => ['tag' => 'Auth', 'summary' => ['post' => 'Change own password'], 'desc' => ['post' => 'Authenticated. Requires Authorization: Bearer <user_jwt>. Change own password with current password verification.
Header: Authorization: Bearer <user_jwt> + Content-Type: application/json
Curl:
curl -X POST /api/v1/app/users/change-password -H "Authorization: Bearer <user_jwt>" -H "Content-Type: application/json" -d \'{"currentPassword":"oldP@ssw0rd","newPassword":"NewP@ssw0rd123"}\'
→ 200 {code:0, message:"Password changed"}
Example payload: {"currentPassword":"oldP@ssw0rd","newPassword":"NewP@ssw0rd123"}
Password strength enforced on newPassword.
Success: 200 {code:0}.
Errors: 400 currentPassword missing / newPassword weak / currentPassword incorrect (service throws InvalidArgumentException); 401 Not authenticated / not User instance; 403 Missing ROLE_USER.']],
        '/app/users/change-password' => ['tag' => 'Auth', 'summary' => ['post' => 'Change own password (alias)'], 'desc' => ['post' => 'Alias of /api/v1/app/users/change-password. Authenticated. Requires Authorization: Bearer <user_jwt>. POST {"currentPassword":"old","newPassword":"new"} → 200
Errors: 400 weak password / wrong current; 401 Not authenticated.']],
        '/api/v1/app/profiles' => ['tag' => 'Auth', 'summary' => ['get' => 'Get my profile (Identity Profile)', 'put' => 'Update my profile (Identity Profile)'], 'desc' => ['get' => 'Authenticated. Requires Authorization: Bearer <user_jwt>. User-scoped single resource (commonFilter: user=>currentUser). Returns Profile (id, uuid, user, level, nickname, avatar, metadata, joinedAt). Level enum: bronze|silver|gold|platinum|diamond (default bronze).
Header: Authorization: Bearer <user_jwt>
Curl:
curl -H "Authorization: Bearer <user_jwt>" /api/v1/app/profiles
→ 200 {data:{id,uuid,level:"bronze",nickname:"Alice",avatar:"/uploads/a.jpg",metadata:{},joinedAt}}
Supports @expands=user, @select.
Errors: 401 Not authenticated; 403 Missing ROLE_USER; 404 if no profile yet (then PUT creates).', 'put' => 'Authenticated. Requires Authorization: Bearer <user_jwt>. Create or update own Profile. Allowed fields: nickname, avatar, metadata. User binding automatic via defaultCreateValues (user=currentUser, level=bronze).
Header: Authorization: Bearer <user_jwt> + Content-Type: application/json
Curl (update):
curl -X PUT /api/v1/app/profiles -H "Authorization: Bearer <user_jwt>" -H "Content-Type: application/json" -d \'{"nickname":"Alice V2","avatar":"/uploads/b.jpg","metadata":{"bio":"hello"}}\'
→ 200 {data:{nickname:"Alice V2"}}
Example payload (nickname): {"nickname":"Alice"}
Example payload (avatar): {"avatar":"https://example.com/avatar.jpg"}
Example payload (metadata): {"metadata":{"bio":"hello","prefs":{"theme":"dark"}}}
Example payload (full): {"nickname":"Alice","avatar":"/uploads/a.jpg","metadata":{"bio":"hello"}}
If no Profile exists, PUT creates one (service->update with new entity + defaultCreateValues).
Success: 200 + profile.
Errors: 400 Validation (nickname too long etc); 401 Not authenticated; 403 Missing ROLE_USER.']],
        '/app/profiles' => ['tag' => 'Auth', 'summary' => ['get' => 'Get my profile (alias)', 'put' => 'Update my profile (alias)'], 'desc' => ['get' => 'Alias of /api/v1/app/profiles GET. Authenticated. Requires Authorization: Bearer <user_jwt>. → 200 {data:{nickname,level}}
Errors: 401 Not authenticated.', 'put' => 'Alias of /api/v1/app/profiles PUT. Authenticated. PUT {"nickname":"new"} → 200
Errors: 401 Not authenticated.']],
        '/api/v1/manage/users' => ['tag' => 'Auth', 'summary' => ['get' => 'List users (admin)', 'post' => 'Create user (admin)'], 'desc' => ['get' => 'Admin. Requires Authorization: Bearer <admin_jwt> + ROLE_ADMIN. Paginated user list. Supports @filter, @dql, @order, @select, @sort, @expands, @display.
Header: Authorization: Bearer <admin_jwt>
Examples:
GET /api/v1/manage/users?page=1&limit=20 → {data:[{id,uuid,email,username,phone,phoneVerified,roles}], paginator}
GET /api/v1/manage/users?@filter=entity.email=="user@example.com"
GET /api/v1/manage/users?@filter=entity.username=="newuser"
GET /api/v1/manage/users?@order=createdAt|DESC
Curl: curl -H "Authorization: Bearer <admin_jwt>" "/api/v1/manage/users?page=1&limit=20"
Success: 200 {data, paginator}
Errors: 401 Unauthorized (missing/invalid JWT); 403 Forbidden (requires ROLE_ADMIN).', 'post' => 'Admin. Requires Authorization: Bearer <admin_jwt> + ROLE_ADMIN. Create user (email, username, password required). Optional: phone, phoneVerified (bool), roles (array).
Header: Authorization: Bearer <admin_jwt> + Content-Type: application/json
Curl:
curl -X POST /api/v1/manage/users -H "Authorization: Bearer <admin_jwt>" -H "Content-Type: application/json" -d \'{"email":"user2@example.com","username":"user2","password":"P@ssw0rd","phone":"+8613912345678","phoneVerified":false,"roles":["ROLE_USER"]}\'
→ 201 {data:{id,uuid,email,username}}
Example payload (minimal): {"email":"user@example.com","username":"newuser","password":"P@ssw0rd"}
Example payload (full): {"email":"user@example.com","username":"newuser","password":"P@ssw0rd","phone":"+8613912345678","phoneVerified":true,"roles":["ROLE_USER","ROLE_ADMIN"]}
Batch: POST array [{email,...},{email,...}] → 201 [{...}] (via CreateApiViewMixin)
Success: 201 + user.
Errors: 400 Missing required fields / weak password / invalid email; 409 Duplicate email/username/phone; 401/403 auth.']],
        '/api/v1/manage/users/{id}' => ['tag' => 'Auth', 'summary' => ['get' => 'Get user detail (admin)', 'put' => 'Update user (admin)', 'delete' => 'Delete user (admin)'], 'desc' => ['get' => 'Admin. Requires Authorization: Bearer <admin_jwt> + ROLE_ADMIN. Get single user by numeric id.
Header: Authorization: Bearer <admin_jwt>
Curl: curl -H "Authorization: Bearer <admin_jwt>" /api/v1/manage/users/1
→ 200 {data:{id,uuid,email,username,phone,phoneVerified,roles,createdAt}}
Supports @select, @expands.
Errors: 401/403 auth; 404 Entity is not found (unknown id).', 'put' => 'Admin. Requires Authorization: Bearer <admin_jwt> + ROLE_ADMIN. Update user fields: email, username, password, phone, phoneVerified, roles.
Header: Authorization: Bearer <admin_jwt> + Content-Type: application/json
Curl:
curl -X PUT /api/v1/manage/users/1 -H "Authorization: Bearer <admin_jwt>" -H "Content-Type: application/json" -d \'{"email":"new@example.com","phoneVerified":true}\'
→ 200 {data:{id,email}}
Example payload: {"email":"new@example.com","username":"newname","phone":"+8613912345678","phoneVerified":true,"roles":["ROLE_ADMIN"]}
Success: 200 + updated user.
Errors: 400 Validation / duplicate; 401/403; 404 Not found.', 'delete' => 'Admin. Requires Authorization: Bearer <admin_jwt> + ROLE_ADMIN. Delete user by id (hard delete via RestController::remove).
Header: Authorization: Bearer <admin_jwt>
Curl: curl -X DELETE -H "Authorization: Bearer <admin_jwt>" /api/v1/manage/users/1
→ 204 / 200 {code:0}
Errors: 401/403; 404 Not found.']],
        '/api/v1/manage/users/{id}/change-password' => ['tag' => 'Auth', 'summary' => ['post' => 'Admin change user password (no current password)'], 'desc' => ['post' => 'Admin. Requires Authorization: Bearer <admin_jwt> + ROLE_ADMIN. No current password required. Directly set new password for any user.
Header: Authorization: Bearer <admin_jwt> + Content-Type: application/json
Curl:
curl -X POST /api/v1/manage/users/1/change-password -H "Authorization: Bearer <admin_jwt>" -H "Content-Type: application/json" -d \'{"newPassword":"NewP@ssw0rd123"}\'
→ 200 {code:0, message:"Password changed"}
Example payload: {"newPassword":"NewP@ssw0rd123"}
Password strength enforced.
Success: 200.
Errors: 400 newPassword missing / weak; 401/403 auth; 404 User not found (id invalid).']],
        '/manage/users' => ['tag' => 'Auth', 'summary' => ['get' => 'List users (alias)', 'post' => 'Create user (alias)'], 'desc' => ['get' => 'Alias of /api/v1/manage/users GET. Admin. Requires Authorization: Bearer <admin_jwt>. Supports @filter. → 200 {data}
Errors: 401/403.', 'post' => 'Alias of /api/v1/manage/users POST. Admin. POST {"email":"...","username":"...","password":"..."} → 201
Errors: 400/409.']],
        '/api/v1/manage/profiles' => ['tag' => 'Auth', 'summary' => ['get' => 'List profiles (admin)', 'post' => 'Create profile (admin)'], 'desc' => ['get' => 'Admin. Requires Authorization: Bearer <admin_jwt> + ROLE_ADMIN. Paginated profile list. Supports @filter, @dql, @order, @select, @expands. Fields: id, uuid, user, level, nickname, avatar, metadata, joinedAt.
Header: Authorization: Bearer <admin_jwt>
Examples:
GET /api/v1/manage/profiles?page=1&limit=20 → {data:[{id,uuid,level,nickname}]}
GET /api/v1/manage/profiles?@filter=entity.level=="bronze"
Curl: curl -H "Authorization: Bearer <admin_jwt>" /api/v1/manage/profiles
Errors: 401/403.', 'post' => 'Admin. Requires Authorization: Bearer <admin_jwt> + ROLE_ADMIN. Create profile. Required: user (user id), level (bronze|silver|gold|platinum|diamond). Optional: nickname, avatar, metadata, joinedAt.
Header: Authorization: Bearer <admin_jwt> + Content-Type: application/json
Curl:
curl -X POST /api/v1/manage/profiles -H "Authorization: Bearer <admin_jwt>" -H "Content-Type: application/json" -d \'{"user":1,"level":"bronze","nickname":"Alice","avatar":"/uploads/a.jpg","metadata":{"bio":"hello"}}\'
→ 201 {data:{id,uuid,level}}
Example payload: {"user":1,"level":"silver","nickname":"Alice","avatar":"https://example.com/a.jpg","metadata":{"bio":"hi"}}
Success: 201.
Errors: 400 Missing user/level / invalid level; 401/403.']],
        '/api/v1/manage/profiles/{id}' => ['tag' => 'Auth', 'summary' => ['get' => 'Get profile detail (admin)', 'put' => 'Update profile (admin)', 'delete' => 'Delete profile (admin)'], 'desc' => ['get' => 'Admin. Requires Authorization: Bearer <admin_jwt>. GET /api/v1/manage/profiles/1 → {data:{id,uuid,level,nickname}}
Errors: 401/403/404.', 'put' => 'Admin. Requires Authorization: Bearer <admin_jwt>. Update profile: level, nickname, avatar, metadata, joinedAt.
Curl: curl -X PUT /api/v1/manage/profiles/1 -H "Authorization: Bearer <admin_jwt>" -H "Content-Type: application/json" -d \'{"nickname":"Alice V2","level":"gold"}\'
→ 200
Example payload: {"level":"gold","nickname":"Alice V2","avatar":"/uploads/b.jpg","metadata":{}}
Errors: 400/401/403/404.', 'delete' => 'Admin. Requires Authorization: Bearer <admin_jwt>. DELETE /api/v1/manage/profiles/1 → 200
Errors: 401/403/404.']],
    ];

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $pathInfo = $request->getPathInfo();

        if ($pathInfo !== '/api/doc.json' && $pathInfo !== '/api/doc') {
            return;
        }

        $response = $event->getResponse();
        $content = $response->getContent();
        if ($content === false || $content === '') {
            return;
        }

        // /api/doc.json — raw JSON
        if ($pathInfo === '/api/doc.json' || str_starts_with(trim($content), '{')) {
            $spec = json_decode($content, true);
            if (!is_array($spec) || !isset($spec['paths'])) {
                return;
            }
            $spec = $this->enrich($spec);
            $encoded = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                $response->setContent($encoded);
            }
            return;
        }

        // /api/doc — HTML with embedded <script id="swagger-data" type="application/json">...</script>
        $pattern = '#<script id="swagger-data" type="application/json">(.*?)</script>#s';
        if (preg_match($pattern, $content, $matches)) {
            $wrapper = json_decode($matches[1], true);
            if (is_array($wrapper) && isset($wrapper['spec'])) {
                $wrapper['spec'] = $this->enrich($wrapper['spec']);
                $newJson = json_encode($wrapper, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($newJson)) {
                $content = str_replace($matches[1], $newJson, $content);
                $response->setContent($content);
            }
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function enrich(array $spec): array
    {
        // Start with known tags; dynamically detected ones will be appended
        $spec['tags'] = $spec['tags'] ?? [];
        foreach ([
            ['name' => 'Auth', 'description' => 'Login, OTP, token refresh, logout'],
            ['name' => 'Products', 'description' => 'Product and Specification CRUD + public listing'],
            ['name' => 'Orders', 'description' => 'Order lifecycle: draft→pending→confirmed→paid→fulfilled→completed→refunded'],
            ['name' => 'Categories', 'description' => 'Hierarchical category management'],
            ['name' => 'Tags', 'description' => 'Flat tag/label system'],
            ['name' => 'Contents', 'description' => 'Article-like content'],
            ['name' => 'Comments', 'description' => 'Polymorphic comment system'],
            ['name' => 'Pages', 'description' => 'Standalone page management'],
            ['name' => 'Media', 'description' => 'File metadata management'],
            ['name' => 'Settings', 'description' => 'Key-value configuration'],
            ['name' => 'Pictures', 'description' => 'Picture management with category binding'],
            ['name' => 'Payment', 'description' => 'Payment invoices, gateways, refunds, and provider callbacks'],
            ['name' => 'Wallet', 'description' => 'Balance, transactions, atomic transfers, vouchers'],
            ['name' => 'Store', 'description' => 'Multi-store operations, membership, and store orders'],
            ['name' => 'Inventory', 'description' => 'Materials, stock, recipes, reservations, ledger'],
            ['name' => 'Promotions', 'description' => 'Promotion DSL and order discounts'],
            ['name' => 'PromotionTemplates', 'description' => 'Promotion template CRUD, validation, and dry-run'],
            ['name' => 'Settlement', 'description' => 'Settlement rules, plans, allocations, and outbox'],
            ['name' => 'Authorization', 'description' => 'RBAC: roles, permissions, assignments, field grants, audit logs'],
            ['name' => 'System', 'description' => 'Entity metadata introspection and route listing'],
            ['name' => 'Wechat', 'description' => 'WeChat Mini Program / Official Account login and WeChat Pay'],
        ] as $t) {
            $this->ensureTag($spec['tags'], (string) $t['name']);
        }

        foreach ($spec['paths'] as $path => &$methods) {
            // Pick the first operation to get the operationId (same route for all methods)
            $firstOp = null;
            foreach ($methods as $op) { if (is_array($op)) { $firstOp = $op; break; } }
            // Apply explicit overrides from META map (for custom summaries/descriptions)
            $meta = self::META[$path] ?? null;
            $tag = $meta['tag'] ?? $this->detectTag($firstOp ?? []);
            if ($tag === null) continue;

            foreach ($methods as $method => &$op) {
                if (!is_array($op)) continue;
                $op['tags'] = [$tag];
                // @phpstan-ignore-next-line
                $this->ensureTag($spec['tags'], $tag);
                if ($meta && isset($meta['summary'][$method])) $op['summary'] = $meta['summary'][$method];
                if ($meta && isset($meta['desc'][$method])) $op['description'] = $meta['desc'][$method];
                // Central requestBody injection — single place for all custom endpoints (no controller OA needed)
                // Central definitions take precedence over generic OA\RequestBody from mixins.
                $body = $this->centralRequestBody($path, $method);
                if ($body !== null) {
                    $op['requestBody'] = $body;
                } elseif (!isset($op['requestBody']) && $method === 'post' && in_array($path, ['/api/v1/app/media/upload', '/api/v1/manage/media/upload'], true)) {
                    $op['requestBody'] = $this->mediaUploadRequestBody();
                }
                // Fallback description for any endpoint not in META — keeps AI doc complete
                if (empty($op['description']) || str_starts_with($op['description'], 'Api ') || $op['description'] === 'Success') {
                    $op['description'] = $this->fallbackDescription($path, $method);
                }
                // Ensure path parameters from URL template are documented
                $this->ensurePathParameters($op, $path);
                // Ensure at least a default success response
                if (!isset($op['responses']) || $op['responses'] === []) {
                    $op['responses'] = ['200' => ['description' => 'Success']];
                }
            }
            unset($op);
        }
        unset($methods);

        // Remove generic operation-type tags (List, Detail, Create, Update, Delete)
        // that come from View mixin OA attributes — we use module tags instead.
        // Also purge stale split Authorization tags (now consolidated under Authorization).
        $genericTags = ['List', 'Detail', 'Create', 'Update', 'Delete', 'Workflow', 'Assignments', 'Roles', 'Permissions', 'Audit'];
        $spec['tags'] = array_values(array_filter($spec['tags'], fn($t) => !in_array($t['name'], $genericTags, true)));

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaUploadRequestBody(): array
    {
        return [
            'required' => true,
            'content' => [
                'multipart/form-data' => [
                    'schema' => [
                        'type' => 'object',
                        'required' => ['file'],
                        'properties' => [
                            'file' => [
                                'type' => 'string',
                                'format' => 'binary',
                                'description' => 'File to upload. Default allowlist: image/jpeg, image/png, image/gif, image/webp, application/pdf. Default max size: 10 MB.',
                            ],
                            'storage' => [
                                'type' => 'string',
                                'enum' => ['local', 'qiniu'],
                                'default' => 'local',
                                'description' => 'Storage driver name. Omit to use media.storage.default / MEDIA_STORAGE_DEFAULT.',
                            ],
                            'category' => [
                                'type' => 'integer',
                                'description' => 'Optional common_category id to bind to the media. Invalid ids return Category is not found.',
                            ],
                            'alt' => ['type' => 'string', 'description' => 'Alternative text for images.'],
                            'title' => ['type' => 'string', 'description' => 'Display title.'],
                            'width' => ['type' => 'integer', 'description' => 'Optional explicit width. Images are auto-detected when omitted.'],
                            'height' => ['type' => 'integer', 'description' => 'Optional explicit height. Images are auto-detected when omitted.'],
                        ],
                    ],
                    'encoding' => [
                        'file' => ['contentType' => 'application/octet-stream'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Central requestBody definitions — single place for all custom endpoints.
     * No controller OA needed. References schemas defined in nelmio_api_doc.yaml.
     * @return array<string, mixed>|null
     */
    private function centralRequestBody(string $path, string $method): ?array
    {
        $key = $method.':'.$path;
        // Helper to wrap a schema or inline properties into a JSON requestBody
        $ref = fn(string $schema) => [
            'required' => true,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]],
        ];
        $inline = fn(array $props, array $req = [], bool $required = true) => [
            'required' => $required,
            'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $props, 'required' => $req]]],
        ];
        return match ($key) {
            // ---- Trade: orders ----
            'post:/api/v1/manage/orders' => $ref('OrderCreate'),
            'post:/api/v1/manage/orders/quote' => $inline([
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['specificationId','quantity'], 'properties' => ['specificationId'=>['type'=>'integer'],'quantity'=>['type'=>'integer','minimum'=>1]]]],
                'currency' => ['type'=>'string','example'=>'CNY'],
                'notes' => ['type'=>'string'],
                'metadata' => ['type'=>'object'],
                'meta' => ['type'=>'object','description'=>'Opaque channel for calculators'],
                'user' => ['type'=>'integer','description'=>'User ID (admin only)'],
            ], ['items']),
            'put:/api/v1/manage/orders/{id}' => $ref('OrderUpdate'),
            'post:/api/v1/manage/orders/{id}/pay' => $inline(['systemWalletId'=>['type'=>'integer','example'=>1], 'paymentMethod'=>['type'=>'string','example'=>'wallet']], ['systemWalletId']),
            'post:/api/v1/manage/orders/{id}/payment' => $inline(['payment'=>['type'=>'string','enum'=>['wallet','mock','wechat'],'example'=>'wallet']], ['payment']),
            'post:/api/v1/manage/orders/{id}/fulfill' => $ref('OrderFulfill'),
            'post:/api/v1/manage/orders/{id}/refund' => $ref('OrderRefund'),
            'post:/api/v1/manage/orders/{id}/do/{transition}' => $inline(['data'=>['type'=>'object','description'=>'Optional transition payload']], [], false),
            'post:/api/v1/app/orders' => $inline([
                'items' => ['type'=>'array','items'=>['type'=>'object','required'=>['specificationId','quantity'],'properties'=>['specificationId'=>['type'=>'integer'],'quantity'=>['type'=>'integer']]]],
                'currency'=>['type'=>'string','example'=>'CNY'],
                'notes'=>['type'=>'string'],
                'metadata'=>['type'=>'object'],
                'meta'=>['type'=>'object'],
            ], ['items']),
            'post:/api/v1/app/orders/quote' => $inline([
                'items' => ['type'=>'array','items'=>['type'=>'object','required'=>['specificationId','quantity'],'properties'=>['specificationId'=>['type'=>'integer'],'quantity'=>['type'=>'integer']]]],
                'currency'=>['type'=>'string','example'=>'CNY'],
                'metadata'=>['type'=>'object'],
                'meta'=>['type'=>'object'],
            ], ['items']),
            'post:/api/v1/app/orders/{id}/payment' => $inline(['payment'=>['type'=>'string','enum'=>['wallet','mock','wechat'],'example'=>'wallet']], ['payment']),
            // submit/confirm/cancel have no body
            // ---- Payment: invoices ----
            'post:/api/v1/manage/invoices' => $ref('InvoiceCreate'),
            'post:/api/v1/manage/invoices/{id}/pay/{payment}' => $inline(['walletId'=>['type'=>'integer','description'=>'Wallet id for wallet_balance deduction'], 'amount'=>['type'=>'integer','description'=>'Optional deduction amount in cents']], [], false),
            'post:/api/v1/manage/invoices/{id}/cancel' => $inline(['reason'=>['type'=>'string','example'=>'User request']], [], false),
            'post:/api/v1/manage/invoices/{id}/refund' => $ref('InvoiceRefundRequest'),
            'post:/api/v1/app/invoices/{id}/pay/{payment}' => $inline(['walletId'=>['type'=>'integer'], 'amount'=>['type'=>'integer']], [], false),
            'post:/api/payment/notify/{payment}' => $inline(['payload'=>['type'=>'object','description'=>'Gateway-specific callback payload (e.g. WeChat Pay V3)']], [], false),
            // ---- Wallet: vouchers ----
            'post:/api/v1/manage/vouchers/deposit' => $ref('VoucherDepositRequest'),
            'post:/api/v1/manage/vouchers/withdraw' => $ref('VoucherWithdrawRequest'),
            'post:/api/v1/manage/vouchers/{uuid}/reverse' => $inline(['reason'=>['type'=>'string','example'=>'Reversal reason']], [], false),
            'post:/api/v1/app/vouchers/deposit' => $inline(['walletId'=>['type'=>'integer'],'amount'=>['type'=>'integer','minimum'=>1],'currency'=>['type'=>'string','example'=>'CNY'],'voucherType'=>['type'=>'string','example'=>'manual'],'voucherId'=>['type'=>'string'],'referenceId'=>['type'=>'string'],'reason'=>['type'=>'string']], ['walletId','amount','voucherType','voucherId','referenceId']),
            'post:/api/v1/app/vouchers/withdraw' => $inline(['walletId'=>['type'=>'integer'],'amount'=>['type'=>'integer'],'voucherType'=>['type'=>'string'],'voucherId'=>['type'=>'string'],'referenceId'=>['type'=>'string'],'reason'=>['type'=>'string']], ['walletId','amount','voucherType','voucherId','referenceId']),
            'post:/api/v1/app/vouchers/{uuid}/reverse' => $inline(['reason'=>['type'=>'string']], [], false),
            'post:/api/v1/manage/wallets/reconcile' => $inline([], [], false),
            // ---- Manage: products ----
            'post:/api/v1/manage/products' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'iPhone 15 Pro', 'description' => 'Product name, required'],
                        'description' => ['type' => 'string', 'example' => 'The latest iPhone with A17 Pro chip', 'nullable' => true, 'description' => 'Optional description'],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive'], 'example' => 'active', 'description' => 'Status, default active'],
                        'store' => ['type' => 'string', 'format' => 'uuid', 'example' => '550e8400-e29b-41d4-a716-446655440000', 'nullable' => true, 'description' => 'Store UUID to bind product to store scope (store scopeId). Null or omitted = global product.'],
                        'metadata' => ['type' => 'object', 'nullable' => true, 'description' => 'Opaque metadata JSON', 'example' => ['brand' => 'Apple']],
                    ]],
                    'example' => ['name' => 'iPhone 15 Pro', 'description' => 'The latest iPhone', 'status' => 'active', 'store' => '550e8400-e29b-41d4-a716-446655440000', 'metadata' => ['brand' => 'Apple']],
                ]],
            ],
            'put:/api/v1/manage/products/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'iPhone 15 Pro Max'],
                        'description' => ['type' => 'string', 'example' => 'Updated description', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive'], 'example' => 'active'],
                        'store' => ['type' => 'string', 'format' => 'uuid', 'example' => '550e8400-e29b-41d4-a716-446655440000', 'nullable' => true, 'description' => 'Store UUID or null to detach (make global)'],
                        'metadata' => ['type' => 'object', 'nullable' => true, 'example' => ['brand' => 'Apple']],
                    ]],
                    'example' => ['name' => 'iPhone 15 Pro Max', 'description' => 'Updated', 'status' => 'active', 'store' => null, 'metadata' => ['brand' => 'Apple']],
                ]],
            ],
            'post:/api/v1/manage/products/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1, 'description' => 'Match key when @basis=id'],
                        'uuid' => ['type' => 'string', 'format' => 'uuid', 'example' => '550e8400-e29b-41d4-a716-446655440000', 'description' => 'Match key when @basis=uuid'],
                        'name' => ['type' => 'string', 'example' => 'iPhone 15 Pro Max'],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive'], 'example' => 'active'],
                        'store' => ['type' => 'string', 'format' => 'uuid', 'example' => '550e8400-e29b-41d4-a716-446655440000', 'nullable' => true, 'description' => 'Store scopeId UUID'],
                        'metadata' => ['type' => 'object', 'nullable' => true],
                    ]]],
                    'example' => [['id' => 1, 'name' => 'iPhone 15 Pro Max', 'status' => 'active'], ['name' => 'New Product', 'description' => 'Fresh', 'status' => 'active', 'store' => '550e8400-e29b-41d4-a716-446655440000']],
                ]],
            ],
            'post:/api/v1/manage/products/{productId}/specifications' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name','price'], 'properties' => [
                        'name' => ['type' => 'string', 'example' => '128GB Silver', 'description' => 'Specification name (SKU), required'],
                        'price' => ['type' => 'integer', 'example' => 699900, 'description' => 'Price in cents (e.g. 699900 = ¥6999.00, 1999 = ¥19.99), must be >=0, required'],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive'], 'example' => 'active', 'description' => 'Status, default active'],
                        'sort' => ['type' => 'integer', 'example' => 1, 'description' => 'Display order, lower = earlier'],
                    ]],
                    'example' => ['name' => '128GB Silver', 'price' => 699900, 'status' => 'active', 'sort' => 1],
                ]],
            ],
            'put:/api/v1/manage/products/{productId}/specifications/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'example' => '256GB Deep Black'],
                        'price' => ['type' => 'integer', 'example' => 899900, 'description' => 'Price in cents'],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive'], 'example' => 'active'],
                        'sort' => ['type' => 'integer', 'example' => 2],
                    ]],
                    'example' => ['name' => '256GB Deep Black', 'price' => 899900, 'status' => 'active', 'sort' => 2],
                ]],
            ],
            'post:/api/v1/manage/products/{productId}/specifications/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 10],
                        'uuid' => ['type' => 'string', 'format' => 'uuid', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
                        'name' => ['type' => 'string', 'example' => '256GB Black'],
                        'price' => ['type' => 'integer', 'example' => 899900, 'description' => 'Price in cents'],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive'], 'example' => 'active'],
                        'sort' => ['type' => 'integer', 'example' => 2],
                    ]]],
                    'example' => [['id' => 10, 'name' => '128GB Silver V2', 'price' => 699900], ['name' => '256GB Black', 'price' => 899900, 'status' => 'active', 'sort' => 2]],
                ]],
            ],
            'post:/api/v1/manage/specifications' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name','product','price'], 'properties' => [
                        'name' => ['type' => 'string', 'example' => '128GB Silver'],
                        'product' => ['type' => 'integer', 'example' => 1, 'description' => 'Product id (int) to link, required'],
                        'price' => ['type' => 'integer', 'example' => 699900, 'description' => 'Price in cents'],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive'], 'example' => 'active'],
                        'sort' => ['type' => 'integer', 'example' => 1],
                    ]],
                    'example' => ['name' => '128GB Silver', 'product' => 1, 'price' => 699900, 'status' => 'active', 'sort' => 1],
                ]],
            ],
            'put:/api/v1/manage/specifications/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'example' => '256GB Deep Black'],
                        'product' => ['type' => 'integer', 'example' => 1],
                        'price' => ['type' => 'integer', 'example' => 899900],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive']],
                        'sort' => ['type' => 'integer', 'example' => 2],
                    ]],
                    'example' => ['name' => '256GB Deep Black', 'product' => 1, 'price' => 899900],
                ]],
            ],
            'post:/api/v1/manage/specifications/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'name' => ['type' => 'string', 'example' => 'Updated Spec'],
                        'product' => ['type' => 'integer', 'example' => 1],
                        'price' => ['type' => 'integer', 'example' => 699900],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive']],
                        'sort' => ['type' => 'integer', 'example' => 2],
                    ]]],
                    'example' => [['id' => 1, 'price' => 799900], ['name' => 'New Spec', 'product' => 1, 'price' => 899900]],
                ]],
            ],
            // ---- Store: scoped products/specifications/orders ----
            'post:/api/v1/store/{scopeId}/products' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'iPhone 15 Pro', 'description' => 'Required. Product name. Store scopeId from path auto-binds store.'],
                        'description' => ['type' => 'string', 'example' => 'The latest iPhone', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive'], 'example' => 'active'],
                        'metadata' => ['type' => 'object', 'nullable' => true, 'description' => 'Opaque metadata', 'example' => ['brand' => 'Apple']],
                    ]],
                    'example' => ['name' => 'iPhone 15 Pro', 'description' => 'The latest iPhone', 'status' => 'active', 'metadata' => ['brand' => 'Apple']],
                ]],
            ],
            'put:/api/v1/store/{scopeId}/products/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'iPhone 15 Pro Max'],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive']],
                        'metadata' => ['type' => 'object', 'nullable' => true],
                    ]],
                    'example' => ['name' => 'iPhone 15 Pro Max', 'status' => 'active'],
                ]],
            ],
            'post:/api/v1/store/{scopeId}/products/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'uuid' => ['type' => 'string', 'format' => 'uuid'],
                        'name' => ['type' => 'string', 'example' => 'Store Product'],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive']],
                        'metadata' => ['type' => 'object', 'nullable' => true],
                    ]]],
                    'example' => [['id' => 1, 'name' => 'Updated Store Product'], ['name' => 'New Store Product', 'status' => 'active']],
                ]],
            ],
            'post:/api/v1/store/{scopeId}/products/{productUuid}/specifications' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name','price'], 'properties' => [
                        'name' => ['type' => 'string', 'example' => '128GB Silver', 'description' => 'SKU name'],
                        'price' => ['type' => 'integer', 'example' => 699900, 'description' => 'Price in cents (e.g. 699900 = ¥6999)'],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive'], 'example' => 'active'],
                        'sort' => ['type' => 'integer', 'example' => 1],
                    ]],
                    'example' => ['name' => '128GB Silver', 'price' => 699900, 'status' => 'active', 'sort' => 1],
                ]],
            ],
            'put:/api/v1/store/{scopeId}/products/{productUuid}/specifications/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'example' => '256GB Deep Black'],
                        'price' => ['type' => 'integer', 'example' => 899900, 'description' => 'Price in cents'],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive']],
                        'sort' => ['type' => 'integer', 'example' => 2],
                    ]],
                    'example' => ['name' => '256GB Deep Black', 'price' => 899900],
                ]],
            ],
            'post:/api/v1/store/{scopeId}/products/{productUuid}/specifications/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 10],
                        'name' => ['type' => 'string', 'example' => 'Spec'],
                        'price' => ['type' => 'integer', 'example' => 899900],
                        'status' => ['type' => 'string', 'enum' => ['active','inactive']],
                        'sort' => ['type' => 'integer', 'example' => 2],
                    ]]],
                    'example' => [['id' => 10, 'price' => 799900], ['name' => '256GB Black', 'price' => 899900]],
                ]],
            ],
            // ---- Store: manage stores + scoped orders ----
            'post:/api/v1/manage/stores/{uuid}/status/{status}' => $inline([], [], false),
            'post:/api/v1/manage/stores/{uuid}/members' => $inline(['userUuid'=>['type'=>'string','format'=>'uuid','example'=>'550e8400-e29b-41d4-a716-446655440000'],'role'=>['type'=>'string','enum'=>['owner','manager','clerk','fulfillment'],'example'=>'manager']], ['userUuid','role']),
            'post:/api/v1/store/{scopeId}/orders/{orderUuid}/fulfill' => $inline(['fulfillmentData'=>['type'=>'object','description'=>'Optional fulfillment payload']], [], false),
            'post:/api/v1/store/{scopeId}/orders/{orderUuid}/verify' => $inline([], [], false),
            // ---- Inventory ----
            'post:/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/adjust' => $inline(['quantityDelta'=>['type'=>'string','example'=>'10.000','description'=>'BCMath string'],'reason'=>['type'=>'string'],'referenceId'=>['type'=>'string'],'allowNegativeStock'=>['type'=>'boolean']], ['quantityDelta','reason']),
            'put:/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/policy' => $inline(['allowNegativeStock'=>['type'=>'boolean']], ['allowNegativeStock']),
            'post:/api/v1/manage/inventory/recipes' => $inline(['specificationUuid'=>['type'=>'string','format'=>'uuid'],'lines'=>['type'=>'array','items'=>['type'=>'object','required'=>['materialUuid','quantityPerUnit'],'properties'=>['materialUuid'=>['type'=>'string','format'=>'uuid'],'quantityPerUnit'=>['type'=>'string','example'=>'2.500'],'sort'=>['type'=>'integer']]]]], ['specificationUuid','lines']),
            // ---- Promotion ----
            'post:/api/v1/manage/promotion-templates/{id}/dry-run' => $inline(['order'=>['type'=>'object','description'=>'Sample order'],'meta'=>['type'=>'object']], [], false),
            // validate has no body
            // ---- Settlement ----
            'post:/api/v1/manage/settlement-rule-versions/{uuid}/publish' => $inline([], [], false),
            'post:/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/post' => $inline([], [], false),
            'post:/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/reverse' => $inline(['reversalId'=>['type'=>'string'],'reasonCode'=>['type'=>'string'],'reasonDetail'=>['type'=>'string'],'reason'=>['type'=>'string']], ['reason']),
            // ---- Identity ----
            'put:/api/v1/app/users/me' => $inline(['email'=>['type'=>'string','format'=>'email'],'username'=>['type'=>'string'],'phone'=>['type'=>'string','nullable'=>true],'password'=>['type'=>'string','format'=>'password','nullable'=>true]], [], false),
            'post:/app/users/change-password' => $inline(['currentPassword'=>['type'=>'string','format'=>'password'],'newPassword'=>['type'=>'string','format'=>'password']], ['currentPassword','newPassword']),
            'post:/api/v1/app/users/change-password' => $inline(['currentPassword'=>['type'=>'string','format'=>'password'],'newPassword'=>['type'=>'string','format'=>'password']], ['currentPassword','newPassword']),
            'post:/api/v1/manage/users/{id}/change-password' => $inline(['newPassword'=>['type'=>'string','format'=>'password']], ['newPassword']),
            // ---- Authorization: assignments / roles ----
            'post:/api/v1/manage/assignments' => $inline(['userUuid'=>['type'=>'string','format'=>'uuid','example'=>'550e8400-e29b-41d4-a716-446655440000','description'=>'User UUID (global user)'],'roleUuid'=>['type'=>'string','description'=>'Role UUID or id or code','example'=>'550e8400-e29b-41d4-a716-446655440001'],'role_uuid'=>['type'=>'string','format'=>'uuid','description'=>'Alias for roleUuid'],'roleId'=>['type'=>'string','description'=>'Alias for roleUuid (id|code)'],'scopeType'=>['type'=>'string','enum'=>['global','store'],'example'=>'store'],'scopeUuid'=>['type'=>'string','format'=>'uuid','nullable'=>true,'example'=>'550e8400-e29b-41d4-a716-446655440002','description'=>'Store UUID for store scope, null for global'],'scope_type'=>['type'=>'string','enum'=>['global','store'],'description'=>'Alias for scopeType'],'scope_uuid'=>['type'=>'string','format'=>'uuid','description'=>'Alias for scopeUuid']], ['userUuid','scopeType']),
            'put:/api/v1/manage/assignments/{id}' => $inline(['userUuid'=>['type'=>'string','format'=>'uuid'],'roleUuid'=>['type'=>'string'],'role_uuid'=>['type'=>'string'],'roleId'=>['type'=>'string'],'scopeType'=>['type'=>'string','enum'=>['global','store']],'scopeUuid'=>['type'=>'string','format'=>'uuid','nullable'=>true],'scope_type'=>['type'=>'string'],'scope_uuid'=>['type'=>'string']], [], false),
            'post:/api/v1/manage/assignments/batch-update' => $inline(['items'=>['type'=>'array','description'=>'Array of assignment objects','items'=>['type'=>'object','properties'=>['userUuid'=>['type'=>'string','format'=>'uuid'],'roleUuid'=>['type'=>'string'],'scopeType'=>['type'=>'string','enum'=>['global','store']],'scopeUuid'=>['type'=>'string','format'=>'uuid']]]]], [], false),
            'post:/api/v1/manage/roles' => $inline(['code'=>['type'=>'string','pattern'=>'^[a-z0-9_]+$','example'=>'store_manager','description'=>'Unique role code [a-z0-9_]'],'name'=>['type'=>'string','example'=>'Store Manager'],'scopeType'=>['type'=>'string','enum'=>['global','store'],'example'=>'store'],'uuid'=>['type'=>'string','format'=>'uuid','description'=>'Optional UUID, auto-generated if omitted']], ['code','name','scopeType']),
            'put:/api/v1/manage/roles/{id}' => $inline(['code'=>['type'=>'string','pattern'=>'^[a-z0-9_]+$','example'=>'store_manager_v2'],'name'=>['type'=>'string','example'=>'Store Manager V2']], [], false),
            'post:/api/v1/manage/roles/batch-update' => $inline(['items'=>['type'=>'array','description'=>'Array of role objects','items'=>['type'=>'object','properties'=>['code'=>['type'=>'string'],'name'=>['type'=>'string'],'scopeType'=>['type'=>'string','enum'=>['global','store']]]]]], [], false),
            'post:/api/v1/manage/roles/{uuid}/permissions' => $inline(['permissions'=>['type'=>'array','items'=>['type'=>'string','example'=>'store:product:read'],'description'=>'Array of permission codes [a-z0-9:_]'],'codes'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'Alias for permissions']], [], false),
            'put:/api/v1/manage/roles/{uuid}/field-grants/{resource}/{action}' => $inline(['fields'=>['type'=>'array','items'=>['type'=>'string','example'=>'name'],'description'=>'Array of allowed field names (unique)','example'=>['name','price']]], [], false),
            // ---- Common: contents ----
            'post:/api/v1/manage/contents' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['title'], 'properties' => [
                        'title' => ['type' => 'string', 'example' => 'EDC News: New Store Opening', 'description' => 'Content title, required'],
                        'body' => ['type' => 'string', 'example' => 'We are opening a new store in Xuhui...', 'nullable' => true, 'description' => 'Body text/markdown, nullable'],
                        'category' => ['type' => 'integer', 'example' => 1, 'nullable' => true, 'description' => 'Category id FK to common_category, null for uncategorized (SET NULL on delete)'],
                        'tags' => ['type' => 'array', 'example' => [1,2], 'description' => 'Array of tag ids (ManyToMany via common_content_tag)', 'items' => ['type' => 'integer']],
                        'metadata' => ['type' => 'object', 'nullable' => true, 'description' => 'Opaque JSON metadata', 'example' => ['source' => 'editorial', 'featured' => true]],
                    ]],
                    'example' => ['title' => 'EDC News: New Store Opening', 'body' => 'We are opening a new store in Xuhui...', 'category' => 1, 'tags' => [1,2], 'metadata' => ['source' => 'editorial', 'featured' => true]],
                ]],
            ],
            'put:/api/v1/manage/contents/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'title' => ['type' => 'string', 'example' => 'Updated Title'],
                        'body' => ['type' => 'string', 'example' => 'Revised body with #markdown', 'nullable' => true],
                        'category' => ['type' => 'integer', 'example' => 2, 'nullable' => true, 'description' => 'New category id or null to detach'],
                        'tags' => ['type' => 'array', 'example' => [2,3], 'description' => 'Replace tags with new id array', 'items' => ['type' => 'integer']],
                        'metadata' => ['type' => 'object', 'nullable' => true, 'example' => ['featured' => false]],
                    ]],
                    'example' => ['title' => 'Updated Title', 'body' => 'Revised body', 'category' => 2, 'tags' => [2,3], 'metadata' => ['featured' => false]],
                ]],
            ],
            'post:/api/v1/manage/contents/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1, 'description' => 'Match key when @basis=id'],
                        'title' => ['type' => 'string', 'example' => 'Updated Title'],
                        'body' => ['type' => 'string', 'nullable' => true],
                        'category' => ['type' => 'integer', 'nullable' => true],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'metadata' => ['type' => 'object', 'nullable' => true],
                    ]]],
                    'example' => [['id' => 1, 'title' => 'Updated Title', 'body' => 'Updated body', 'category' => 2, 'tags' => [2]], ['title' => 'New Article', 'body' => 'Fresh body', 'category' => 1, 'tags' => [1]]],
                ]],
            ],
            // ---- Common: comments ----
            'post:/api/v1/manage/comments' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['body','entityType','entityId'], 'properties' => [
                        'body' => ['type' => 'string', 'example' => 'Great article! Thanks for sharing.', 'description' => 'Comment body, required'],
                        'entityType' => ['type' => 'string', 'example' => 'App\\Common\\Entity\\Content', 'description' => 'Polymorphic FQCN, e.g. App\\Common\\Entity\\Content or App\\Common\\Entity\\Page'],
                        'entityId' => ['type' => 'integer', 'example' => 1, 'description' => 'Target entity id'],
                        'authorName' => ['type' => 'string', 'example' => 'Alice', 'nullable' => true, 'description' => 'Author display name'],
                        'authorEmail' => ['type' => 'string', 'example' => 'alice@example.com', 'nullable' => true, 'description' => 'Author email'],
                        'author' => ['type' => 'integer', 'example' => 1, 'nullable' => true, 'description' => 'User id FK, SET NULL on delete'],
                        'parent' => ['type' => 'integer', 'example' => null, 'nullable' => true, 'description' => 'Parent comment id for threaded replies, CASCADE on delete'],
                        'status' => ['type' => 'string', 'example' => 'approved', 'enum' => ['pending','approved','rejected','spam'], 'description' => 'Moderation status, default pending'],
                    ]],
                    'example' => ['body' => 'Great article! Thanks for sharing.', 'entityType' => 'App\\Common\\Entity\\Content', 'entityId' => 1, 'authorName' => 'Alice', 'authorEmail' => 'alice@example.com', 'status' => 'approved', 'parent' => null],
                ]],
            ],
            'put:/api/v1/manage/comments/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'body' => ['type' => 'string', 'example' => 'Updated comment body'],
                        'authorName' => ['type' => 'string', 'example' => 'Alice', 'nullable' => true],
                        'authorEmail' => ['type' => 'string', 'example' => 'alice@example.com', 'nullable' => true],
                        'status' => ['type' => 'string', 'example' => 'approved', 'enum' => ['pending','approved','rejected','spam']],
                    ]],
                    'example' => ['body' => 'Updated comment body', 'authorName' => 'Alice', 'authorEmail' => 'alice@example.com', 'status' => 'approved'],
                ]],
            ],
            'post:/api/v1/manage/comments/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'body' => ['type' => 'string', 'example' => 'Updated body'],
                        'entityType' => ['type' => 'string', 'example' => 'App\\Common\\Entity\\Content'],
                        'entityId' => ['type' => 'integer', 'example' => 1],
                        'authorName' => ['type' => 'string', 'nullable' => true],
                        'authorEmail' => ['type' => 'string', 'nullable' => true],
                        'parent' => ['type' => 'integer', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['pending','approved','rejected','spam']],
                    ]]],
                    'example' => [['id' => 1, 'body' => 'Updated body', 'status' => 'approved'], ['body' => 'New comment', 'entityType' => 'App\\Common\\Entity\\Page', 'entityId' => 2]],
                ]],
            ],
            'post:/api/v1/app/comments' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['body','entityType','entityId'], 'properties' => [
                        'body' => ['type' => 'string', 'example' => 'Great article!', 'description' => 'Comment body, required'],
                        'entityType' => ['type' => 'string', 'example' => 'App\\Common\\Entity\\Content', 'description' => 'Target FQCN'],
                        'entityId' => ['type' => 'integer', 'example' => 1, 'description' => 'Target id'],
                        'parent' => ['type' => 'integer', 'example' => 1, 'nullable' => true, 'description' => 'Parent comment id for reply, optional'],
                    ]],
                    'example' => ['body' => 'Great article!', 'entityType' => 'App\\Common\\Entity\\Content', 'entityId' => 1, 'parent' => null],
                ]],
            ],
            // ---- Common: pages ----
            'post:/api/v1/manage/pages' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['title','slug'], 'properties' => [
                        'title' => ['type' => 'string', 'example' => 'About Us', 'description' => 'Page title, required'],
                        'slug' => ['type' => 'string', 'example' => 'about-us', 'description' => 'Unique slug [a-z0-9_-]'],
                        'body' => ['type' => 'string', 'example' => '# About EDC', 'nullable' => true, 'description' => 'Body markdown/html, nullable'],
                        'metaTitle' => ['type' => 'string', 'example' => 'About EDC - Community', 'nullable' => true, 'description' => 'SEO meta title'],
                        'metaDescription' => ['type' => 'string', 'example' => 'Learn about EDC', 'nullable' => true, 'description' => 'SEO meta description'],
                        'status' => ['type' => 'string', 'example' => 'published', 'enum' => ['draft','published','archived'], 'description' => 'Status, default draft'],
                        'publishedAt' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-09-04T08:00:00+00:00', 'nullable' => true, 'description' => 'Publish timestamp ISO8601'],
                    ]],
                    'example' => ['title' => 'About Us', 'slug' => 'about-us', 'body' => '# About EDC', 'metaTitle' => 'About EDC', 'metaDescription' => 'Learn about EDC', 'status' => 'published', 'publishedAt' => '2026-09-04T08:00:00+00:00'],
                ]],
            ],
            'put:/api/v1/manage/pages/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'title' => ['type' => 'string', 'example' => 'About Us V2'],
                        'slug' => ['type' => 'string', 'example' => 'about-us-v2'],
                        'body' => ['type' => 'string', 'nullable' => true],
                        'metaTitle' => ['type' => 'string', 'nullable' => true],
                        'metaDescription' => ['type' => 'string', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['draft','published','archived']],
                        'publishedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    ]],
                    'example' => ['title' => 'About Us V2', 'slug' => 'about-us-v2', 'body' => 'Updated body', 'metaTitle' => 'Updated SEO', 'metaDescription' => 'Updated desc', 'status' => 'published', 'publishedAt' => '2026-09-04T10:00:00+00:00'],
                ]],
            ],
            'post:/api/v1/manage/pages/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'slug' => ['type' => 'string', 'example' => 'about-us'],
                        'title' => ['type' => 'string', 'example' => 'About Us V2'],
                        'body' => ['type' => 'string', 'nullable' => true],
                        'metaTitle' => ['type' => 'string', 'nullable' => true],
                        'metaDescription' => ['type' => 'string', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['draft','published','archived']],
                        'publishedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    ]]],
                    'example' => [['id' => 1, 'title' => 'About Us V2', 'status' => 'published'], ['title' => 'New Page', 'slug' => 'new-page', 'status' => 'draft']],
                ]],
            ],
            // ---- Common: categories ----
            'post:/api/v1/manage/categories' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name','slug'], 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'News', 'description' => 'Category name, required'],
                        'slug' => ['type' => 'string', 'example' => 'news', 'description' => 'Unique slug [a-z0-9_-], required'],
                        'description' => ['type' => 'string', 'example' => 'EDC news', 'nullable' => true, 'description' => 'Description nullable'],
                        'parent' => ['type' => 'integer', 'example' => 1, 'nullable' => true, 'description' => 'Parent category id, null for root'],
                        'sortOrder' => ['type' => 'integer', 'example' => 1, 'description' => 'Display order, lower=earlier'],
                        'enabled' => ['type' => 'boolean', 'example' => true, 'description' => 'Enabled flag, default true'],
                    ]],
                    'example' => ['name' => 'News', 'slug' => 'news', 'description' => 'EDC news', 'parent' => null, 'sortOrder' => 1, 'enabled' => true],
                ]],
            ],
            'put:/api/v1/manage/categories/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'News Updated'],
                        'slug' => ['type' => 'string', 'example' => 'news-updated'],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'parent' => ['type' => 'integer', 'nullable' => true],
                        'sortOrder' => ['type' => 'integer', 'example' => 2],
                        'enabled' => ['type' => 'boolean', 'example' => true],
                    ]],
                    'example' => ['name' => 'News Updated', 'slug' => 'news-updated', 'sortOrder' => 2, 'enabled' => true],
                ]],
            ],
            'post:/api/v1/manage/categories/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'name' => ['type' => 'string', 'example' => 'News V2'],
                        'slug' => ['type' => 'string', 'example' => 'news-v2'],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'parent' => ['type' => 'integer', 'nullable' => true],
                        'sortOrder' => ['type' => 'integer'],
                        'enabled' => ['type' => 'boolean'],
                    ]]],
                    'example' => [['id' => 1, 'name' => 'News V2', 'slug' => 'news-v2'], ['name' => 'New Category', 'slug' => 'new-cat']],
                ]],
            ],
            // ---- Common: tags ----
            'post:/api/v1/manage/tags' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name','slug'], 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Featured'],
                        'slug' => ['type' => 'string', 'example' => 'featured'],
                        'color' => ['type' => 'string', 'example' => '#ff6600', 'nullable' => true, 'description' => 'Hex color e.g. #ff6600 or null'],
                    ]],
                    'example' => ['name' => 'Featured', 'slug' => 'featured', 'color' => '#ff6600'],
                ]],
            ],
            'put:/api/v1/manage/tags/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Featured V2'],
                        'slug' => ['type' => 'string', 'example' => 'featured-v2'],
                        'color' => ['type' => 'string', 'nullable' => true, 'example' => '#00ff00'],
                    ]],
                    'example' => ['name' => 'Featured V2', 'color' => '#00ff00'],
                ]],
            ],
            'post:/api/v1/manage/tags/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'name' => ['type' => 'string'],
                        'slug' => ['type' => 'string'],
                        'color' => ['type' => 'string', 'nullable' => true],
                    ]]],
                    'example' => [['id' => 1, 'name' => 'Featured V2'], ['name' => 'New Tag', 'slug' => 'new-tag']],
                ]],
            ],
            // ---- Common: media ----
            'post:/api/v1/manage/media' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['filename','originalFilename','mimeType','size','path'], 'properties' => [
                        'filename' => ['type' => 'string', 'example' => 'abc123.jpg'],
                        'originalFilename' => ['type' => 'string', 'example' => 'photo.jpg'],
                        'mimeType' => ['type' => 'string', 'example' => 'image/jpeg'],
                        'size' => ['type' => 'integer', 'example' => 123456, 'description' => 'Bytes'],
                        'path' => ['type' => 'string', 'example' => '/uploads/2026/09/abc123.jpg'],
                        'storage' => ['type' => 'string', 'enum' => ['local','qiniu'], 'example' => 'local'],
                        'category' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                        'user' => ['type' => 'integer', 'nullable' => true],
                        'alt' => ['type' => 'string', 'nullable' => true, 'example' => 'EDC photo'],
                        'title' => ['type' => 'string', 'nullable' => true],
                        'width' => ['type' => 'integer', 'nullable' => true],
                        'height' => ['type' => 'integer', 'nullable' => true],
                    ]],
                    'example' => ['filename' => 'abc123.jpg', 'originalFilename' => 'photo.jpg', 'mimeType' => 'image/jpeg', 'size' => 123456, 'path' => '/uploads/2026/09/abc123.jpg'],
                ]],
            ],
            'put:/api/v1/manage/media/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'filename' => ['type' => 'string'],
                        'originalFilename' => ['type' => 'string'],
                        'mimeType' => ['type' => 'string'],
                        'size' => ['type' => 'integer'],
                        'path' => ['type' => 'string'],
                        'storage' => ['type' => 'string', 'enum' => ['local','qiniu']],
                        'alt' => ['type' => 'string', 'nullable' => true, 'example' => 'New alt'],
                        'title' => ['type' => 'string', 'nullable' => true],
                        'width' => ['type' => 'integer', 'nullable' => true],
                        'height' => ['type' => 'integer', 'nullable' => true],
                    ]],
                    'example' => ['alt' => 'New alt', 'title' => 'New title'],
                ]],
            ],
            'post:/api/v1/manage/media/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'filename' => ['type' => 'string'],
                        'alt' => ['type' => 'string', 'nullable' => true],
                    ]]],
                    'example' => [['id' => 1, 'alt' => 'Updated']],
                ]],
            ],
            // ---- Common: settings ----
            'post:/api/v1/manage/settings' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['key'], 'properties' => [
                        'key' => ['type' => 'string', 'example' => 'site.name', 'description' => 'Unique setting key'],
                        'value' => ['type' => 'string', 'example' => 'EDC Online', 'nullable' => true],
                        'type' => ['type' => 'string', 'example' => 'string', 'description' => 'Type: string|int|bool|json'],
                        'groupName' => ['type' => 'string', 'example' => 'site', 'nullable' => true],
                        'label' => ['type' => 'string', 'nullable' => true],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'sortOrder' => ['type' => 'integer', 'example' => 1],
                    ]],
                    'example' => ['key' => 'site.name', 'value' => 'EDC Online', 'type' => 'string', 'groupName' => 'site'],
                ]],
            ],
            'put:/api/v1/manage/settings/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'key' => ['type' => 'string', 'example' => 'site.name'],
                        'value' => ['type' => 'string', 'nullable' => true],
                        'type' => ['type' => 'string'],
                        'groupName' => ['type' => 'string', 'nullable' => true],
                        'label' => ['type' => 'string', 'nullable' => true],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'sortOrder' => ['type' => 'integer'],
                    ]],
                    'example' => ['value' => 'EDC V2', 'label' => 'Updated'],
                ]],
            ],
            'post:/api/v1/manage/settings/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'key' => ['type' => 'string', 'example' => 'site.name'],
                        'value' => ['type' => 'string'],
                    ]]],
                    'example' => [['key' => 'site.name', 'value' => 'EDC V2']],
                ]],
            ],
            // ---- Pictures (app own, manage admin) ----
            'post:/api/v1/app/pictures' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['category','image'], 'properties' => [
                        'category' => ['type' => 'integer', 'example' => 1, 'description' => 'Category id, required'],
                        'image' => ['type' => 'integer', 'example' => 1, 'description' => 'Media id, required'],
                        'title' => ['type' => 'string', 'example' => 'Summer Trip', 'nullable' => true],
                        'metadata' => ['type' => 'object', 'nullable' => true, 'example' => ['location' => 'Xuhui']],
                    ]],
                    'example' => ['category' => 1, 'image' => 1, 'title' => 'Summer Trip'],
                ]],
            ],
            'put:/api/v1/app/pictures/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'title' => ['type' => 'string', 'example' => 'Summer V2'],
                        'category' => ['type' => 'integer', 'example' => 2],
                        'image' => ['type' => 'integer', 'example' => 1],
                        'metadata' => ['type' => 'object', 'nullable' => true],
                    ]],
                    'example' => ['title' => 'Summer V2', 'category' => 2],
                ]],
            ],
            'post:/api/v1/app/pictures/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'title' => ['type' => 'string'],
                        'category' => ['type' => 'integer'],
                        'image' => ['type' => 'integer'],
                    ]]],
                    'example' => [['id' => 1, 'title' => 'Updated']],
                ]],
            ],
            'post:/api/v1/manage/pictures' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['category','image'], 'properties' => [
                        'category' => ['type' => 'integer', 'example' => 1],
                        'image' => ['type' => 'integer', 'example' => 1],
                        'user' => ['type' => 'integer', 'example' => 1, 'nullable' => true, 'description' => 'Admin can assign user'],
                        'title' => ['type' => 'string', 'nullable' => true, 'example' => 'Admin Pic'],
                        'metadata' => ['type' => 'object', 'nullable' => true],
                    ]],
                    'example' => ['category' => 1, 'image' => 1, 'title' => 'Admin Pic'],
                ]],
            ],
            'put:/api/v1/manage/pictures/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'title' => ['type' => 'string', 'example' => 'New title'],
                        'category' => ['type' => 'integer'],
                        'image' => ['type' => 'integer'],
                        'metadata' => ['type' => 'object', 'nullable' => true],
                    ]],
                    'example' => ['title' => 'New title'],
                ]],
            ],
            'post:/api/v1/manage/pictures/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'title' => ['type' => 'string'],
                    ]]],
                    'example' => [['id' => 1, 'title' => 'Updated']],
                ]],
            ],
            // ---- Wallet ----
            'post:/api/v1/manage/wallets' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['user','currency'], 'properties' => [
                        'user' => ['type' => 'integer', 'example' => 1, 'description' => 'User id, required'],
                        'currency' => ['type' => 'string', 'example' => 'CNY', 'description' => 'Currency code'],
                        'label' => ['type' => 'string', 'example' => 'Alice primary', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['active','frozen'], 'example' => 'active'],
                    ]],
                    'example' => ['user' => 1, 'currency' => 'CNY', 'label' => 'Alice primary', 'status' => 'active'],
                ]],
            ],
            'put:/api/v1/manage/wallets/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['active','frozen'], 'example' => 'frozen'],
                        'label' => ['type' => 'string', 'nullable' => true, 'example' => 'Frozen for audit'],
                    ]],
                    'example' => ['status' => 'frozen', 'label' => 'Frozen for audit'],
                ]],
            ],
            'post:/api/v1/manage/wallets/batch-update' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer', 'example' => 1],
                        'user' => ['type' => 'integer'],
                        'currency' => ['type' => 'string'],
                        'label' => ['type' => 'string', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['active','frozen']],
                    ]]],
                    'example' => [['id' => 1, 'label' => 'Updated']],
                ]],
            ],
            'post:/api/v1/manage/transactions' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['fromWalletId','toWalletId','amount'], 'properties' => [
                        'fromWalletId' => ['type' => 'integer', 'example' => 1],
                        'toWalletId' => ['type' => 'integer', 'example' => 2],
                        'amount' => ['type' => 'integer', 'example' => 10000, 'description' => 'Amount in cents'],
                        'referenceId' => ['type' => 'string', 'example' => 'txn-20250101-001', 'description' => 'Idempotency key'],
                        'description' => ['type' => 'string', 'example' => 'Payment for order #42'],
                    ]],
                    'example' => ['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 10000, 'referenceId' => 'txn-001'],
                ]],
            ],
            // ---- Invoices (already covered but ensure manage create) ----
            // ---- Inventory: materials ----
            'post:/api/v1/manage/inventory/materials' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['code','name','kind','unit'], 'properties' => [
                        'code' => ['type' => 'string', 'example' => 'COFFEE_BEANS'],
                        'name' => ['type' => 'string', 'example' => 'Coffee Beans'],
                        'kind' => ['type' => 'string', 'example' => 'consumable'],
                        'unit' => ['type' => 'string', 'example' => 'g'],
                        'status' => ['type' => 'string', 'example' => 'active'],
                        'metadata' => ['type' => 'object', 'nullable' => true],
                    ]],
                    'example' => ['code' => 'COFFEE_BEANS', 'name' => 'Coffee Beans', 'kind' => 'consumable', 'unit' => 'g'],
                ]],
            ],
            'put:/api/v1/manage/inventory/materials/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Coffee Beans V2'],
                        'kind' => ['type' => 'string'],
                        'unit' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'metadata' => ['type' => 'object', 'nullable' => true],
                    ]],
                    'example' => ['name' => 'Coffee Beans V2'],
                ]],
            ],
            // ---- Promotion ----
            'post:/api/v1/manage/promotions' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name','template'], 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Double 11 Sale'],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'template' => ['type' => 'integer', 'example' => 1, 'description' => 'Promotion template id'],
                        'storeCode' => ['type' => 'string', 'example' => 'XUHUI', 'nullable' => true],
                        'enabled' => ['type' => 'boolean', 'example' => true],
                        'startTime' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-11-11T00:00:00+00:00'],
                        'endTime' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-11-11T23:59:59+00:00'],
                        'config' => ['type' => 'object', 'nullable' => true, 'example' => ['discount' => 1000]],
                        'conflictMode' => ['type' => 'string', 'example' => 'stackable'],
                    ]],
                    'example' => ['name' => 'Double 11 Sale', 'template' => 1, 'enabled' => true, 'config' => ['discount' => 1000]],
                ]],
            ],
            'put:/api/v1/manage/promotions/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Updated Sale'],
                        'template' => ['type' => 'integer'],
                        'enabled' => ['type' => 'boolean'],
                        'config' => ['type' => 'object', 'nullable' => true],
                    ]],
                    'example' => ['name' => 'Updated Sale', 'enabled' => false],
                ]],
            ],
            'post:/api/v1/manage/promotion-templates' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name','type','dsl'], 'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Buy 2 Get 1'],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'type' => ['type' => 'string', 'example' => 'discount'],
                        'phase' => ['type' => 'string', 'example' => 'pre_price'],
                        'enabled' => ['type' => 'boolean', 'example' => true],
                        'dsl' => ['type' => 'string', 'example' => 'if order.total > 10000 then discount 1000'],
                        'fields' => ['type' => 'object', 'nullable' => true],
                    ]],
                    'example' => ['name' => 'Buy 2 Get 1', 'type' => 'discount', 'dsl' => 'if order.total > 10000 then discount 1000'],
                ]],
            ],
            'put:/api/v1/manage/promotion-templates/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string'],
                        'dsl' => ['type' => 'string', 'example' => 'if order.total > 20000 then discount 2000'],
                        'enabled' => ['type' => 'boolean'],
                    ]],
                    'example' => ['dsl' => 'if order.total > 20000 then discount 2000'],
                ]],
            ],
            // ---- Settlement: rule and rule versions ----
            'post:/api/v1/manage/settlement-rules' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['code','name'], 'properties' => [
                        'code' => ['type' => 'string', 'example' => 'PLATFORM_FEE'],
                        'name' => ['type' => 'string', 'example' => 'Platform Fee 5%'],
                    ]],
                    'example' => ['code' => 'PLATFORM_FEE', 'name' => 'Platform Fee 5%'],
                ]],
            ],
            'post:/api/v1/manage/settlement-rule-versions' => [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['ruleUuid','definition','priority','effectiveFrom'], 'properties' => [
                        'ruleUuid' => ['type' => 'string', 'format' => 'uuid', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
                        'definition' => ['type' => 'object', 'example' => ['type' => 'percentage', 'rate' => 5]],
                        'priority' => ['type' => 'integer', 'example' => 10],
                        'effectiveFrom' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-01-01T00:00:00+00:00'],
                        'effectiveTo' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    ]],
                    'example' => ['ruleUuid' => '550e8400-e29b-41d4-a716-446655440000', 'definition' => ['type' => 'percentage', 'rate' => 5], 'priority' => 10, 'effectiveFrom' => '2026-01-01T00:00:00+00:00'],
                ]],
            ],
            'put:/api/v1/manage/settlement-rule-versions/{id}' => [
                'required' => false,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [
                        'definition' => ['type' => 'object'],
                        'priority' => ['type' => 'integer'],
                        'effectiveFrom' => ['type' => 'string', 'format' => 'date-time'],
                        'effectiveTo' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    ]],
                    'example' => ['priority' => 20, 'effectiveFrom' => '2026-02-01T00:00:00+00:00'],
                ]],
            ],
            // ---- Media upload handled separately ----
            'post:/api/auth/register' => $inline(['email'=>['type'=>'string','format'=>'email','example'=>'user@example.com'],'username'=>['type'=>'string','example'=>'newuser'],'password'=>['type'=>'string','format'=>'password','example'=>'P@ssw0rd'],'phone'=>['type'=>'string','example'=>'+8613912345678','nullable'=>true,'description'=>'Optional phone']], ['email','username','password']),
            'post:/api/auth/login' => $inline(['identifier'=>['type'=>'string','example'=>'admin@example.com','description'=>'Email, username, or verified phone'],'password'=>['type'=>'string','format'=>'password','example'=>'P@ssw0rd']], ['identifier','password']),
            'post:/api/auth/otp/request' => $inline(['phone'=>['type'=>'string','example'=>'+8613912345678','description'=>'Phone in E.164'],'purpose'=>['type'=>'string','enum'=>['login','verify_phone'],'example'=>'login','description'=>'Purpose: login|verify_phone']], ['phone','purpose']),
            'post:/api/auth/otp/verify' => $inline(['phone'=>['type'=>'string','example'=>'+8613912345678'],'otp'=>['type'=>'string','example'=>'123456','description'=>'6-digit SMS code'],'purpose'=>['type'=>'string','enum'=>['login','verify_phone'],'example'=>'login']], ['phone','otp','purpose']),
            'post:/api/auth/token/refresh' => $inline(['refresh_token'=>['type'=>'string','example'=>'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...','description'=>'Refresh token from login/refresh']], ['refresh_token']),
            'post:/api/auth/logout' => $inline(['access_token'=>['type'=>'string','example'=>'eyJ...','nullable'=>true,'description'=>'Optional access token to revoke (or via Authorization header)'],'refresh_token'=>['type'=>'string','example'=>'eyJ...','nullable'=>true,'description'=>'Optional refresh token to revoke']], [], false),
            'post:/api/wechat/miniapp/login' => $inline(['js_code'=>['type'=>'string','example'=>'081abc...','description'=>'WeChat wx.login() code']], ['js_code']),
            'post:/api/wechat/miniapp/phone' => $inline(['code'=>['type'=>'string','example'=>'xyz...','description'=>'WeChat getPhoneNumber code']], ['code']),
            'post:/wechat/miniapp/phone' => $inline(['code'=>['type'=>'string','example'=>'xyz...']], ['code']),
            'post:/api/wechat/oauth/callback' => $inline(['code'=>['type'=>'string','example'=>'081abc...','description'=>'WeChat OAuth code']], ['code']),
            'post:/wechat/oauth/callback' => $inline(['code'=>['type'=>'string','example'=>'081abc...']], ['code']),
            'put:/api/v1/app/profiles' => $inline(['nickname'=>['type'=>'string','example'=>'Alice','nullable'=>true,'description'=>'Nickname'],'avatar'=>['type'=>'string','example'=>'/uploads/a.jpg','nullable'=>true,'description'=>'Avatar URL'],'metadata'=>['type'=>'object','nullable'=>true,'description'=>'Opaque metadata']], [], false),
            'put:/app/profiles' => $inline(['nickname'=>['type'=>'string','example'=>'Alice'],'avatar'=>['type'=>'string'],'metadata'=>['type'=>'object']], [], false),
            'post:/api/v1/manage/users' => $inline(['email'=>['type'=>'string','format'=>'email','example'=>'user@example.com'],'username'=>['type'=>'string','example'=>'newuser'],'password'=>['type'=>'string','format'=>'password','example'=>'P@ssw0rd'],'phone'=>['type'=>'string','example'=>'+8613912345678','nullable'=>true],'phoneVerified'=>['type'=>'boolean','example'=>false],'roles'=>['type'=>'array','items'=>['type'=>'string','example'=>'ROLE_USER'],'description'=>'Roles']], ['email','username','password']),
            'put:/api/v1/manage/users/{id}' => $inline(['email'=>['type'=>'string','format'=>'email'],'username'=>['type'=>'string'],'password'=>['type'=>'string','format'=>'password','nullable'=>true],'phone'=>['type'=>'string','nullable'=>true],'phoneVerified'=>['type'=>'boolean'],'roles'=>['type'=>'array','items'=>['type'=>'string']]], [], false),
            'post:/manage/users' => $inline(['email'=>['type'=>'string','format'=>'email','example'=>'user@example.com'],'username'=>['type'=>'string','example'=>'newuser'],'password'=>['type'=>'string','format'=>'password','example'=>'P@ssw0rd']], ['email','username','password']),
            'post:/api/v1/manage/profiles' => $inline(['user'=>['type'=>'integer','example'=>1,'description'=>'User id'],'level'=>['type'=>'string','enum'=>['bronze','silver','gold','platinum','diamond'],'example'=>'bronze'],'nickname'=>['type'=>'string','example'=>'Alice','nullable'=>true],'avatar'=>['type'=>'string','example'=>'/uploads/a.jpg','nullable'=>true],'metadata'=>['type'=>'object','nullable'=>true]], ['user','level']),
            'put:/api/v1/manage/profiles/{id}' => $inline(['level'=>['type'=>'string','enum'=>['bronze','silver','gold','platinum','diamond']],'nickname'=>['type'=>'string','nullable'=>true],'avatar'=>['type'=>'string','nullable'=>true],'metadata'=>['type'=>'object','nullable'=>true]], [], false),
            default => null,
        };
    }

    /**
     * @param string $path
     * @param string $method
     */
    private function fallbackDescription(string $path, string $method): string
    {
        $key = $method.':'.$path;
        return match (true) {
            str_contains($path, '/store/') && str_contains($path, '/orders') && $method === 'get' && str_contains($path, '{orderUuid}') => "Staff scoped StoreOrder detail. Example: GET {$path}  Header: Authorization: Bearer <staff_jwt>\norderUuid can be TradeOrder UUID or StoreOrder UUID. Requires store:order:read.",
            str_contains($path, '/store/') && $method === 'get' => "Staff scoped list. Example: GET {$path}?page=1  Header: Authorization: Bearer <staff_jwt>\nRequires store membership.",
            str_contains($path, '/manage/store-orders') => "Admin StoreOrder list/detail. Supports @filter. Example: GET {$path}  Header: Authorization: Bearer <admin_jwt>",
            str_contains($path, '/app/store-orders') => "Own StoreOrders. Example: GET {$path}  Header: Authorization: Bearer <user_jwt>",
            str_contains($path, '/manage/orders') && $method === 'get' => "List orders with pagination and @filter. Example: GET {$path}?page=1",
            str_contains($path, '/app/orders') && $method === 'get' => "Own orders. Example: GET {$path}  Header: Authorization: Bearer <user_jwt>",
            default => ucfirst($method)." {$path} — see requestBody example and ensure Authorization: Bearer <jwt> header.",
        };
    }

    /**
     * @param array<string, mixed> $operation
     * @param string $path
     */
    private function ensurePathParameters(array &$operation, string $path): void
    {
        if (!preg_match_all('/\{(\w+)\}/', $path, $m)) return;
        $placeholders = $m[1];
        $existing = [];
        foreach ($operation['parameters'] ?? [] as $p) {
            if (($p['name'] ?? null) && ($p['in'] ?? null) === 'path') $existing[] = $p['name'];
        }
        foreach ($placeholders as $name) {
            if (in_array($name, $existing, true)) continue;
            $operation['parameters'][] = ['name'=>$name,'in'=>'path','required'=>true,'schema'=>['type'=>'string']];
        }
    }

    /**
     * Auto-detect module tag from the route name (operationId).
     * Route naming convention: {scope}-{resource}-{action}
     *   e.g. manage-products-list → Products, app-orders-create → Orders
     *
     * Known resources are matched explicitly. Unknown resources are
     * title-cased from the route prefix automatically.
     * @param array<string, mixed> $operation
     */
    private function detectTag(array $operation): ?string
    {
        $opId = $operation['operationId'] ?? '';
        if ($opId === '') return null;

        // Auth routes use a special prefix
        if (str_contains($opId, 'sys-auth')) return 'Auth';

        // System routes: system-entity-*, system-router-* (operationId is "{method}_system-..." )
        if (str_contains($opId, 'system-')) return 'System';

        // Wechat routes: wechat-* (operationId is "{method}_wechat-..." )
        if (str_contains($opId, 'wechat-')) return 'Wechat';

        if (str_contains($opId, 'store-')) return 'Store';

        // Extract resource name: {scope}-{resource} or {scope}-{resource}-{action}
        if (preg_match('/(?:manage|app|public)-([a-z][a-z0-9_]*)(?:-|$)/', $opId, $m)) {
            $resource = $m[1];

            // Map resource names to display names (supports both singular and plural forms)
            $known = [
                'product' => 'Products', 'products' => 'Products',
                'specification' => 'Products', 'specifications' => 'Products',
                'order' => 'Orders', 'orders' => 'Orders',
                'category' => 'Categories', 'categories' => 'Categories',
                'tag' => 'Tags', 'tags' => 'Tags',
                'content' => 'Contents', 'contents' => 'Contents',
                'comment' => 'Comments', 'comments' => 'Comments',
                'page' => 'Pages', 'pages' => 'Pages',
                'media' => 'Media',
                'setting' => 'Settings', 'settings' => 'Settings',
                'picture' => 'Pictures', 'pictures' => 'Pictures',
                'wallet' => 'Wallet', 'wallets' => 'Wallet',
                'transaction' => 'Wallet', 'transactions' => 'Wallet',
                'transfer' => 'Wallet', 'transfers' => 'Wallet',
                'voucher' => 'Wallet', 'vouchers' => 'Wallet',
                'voucher_comment' => 'Wallet', 'voucher_comments' => 'Wallet',
                'payment_deduction' => 'Wallet', 'payment_deductions' => 'Wallet',
                'invoice' => 'Payment', 'invoices' => 'Payment',
                'store' => 'Store', 'stores' => 'Store',
                'store_order' => 'Store', 'store_orders' => 'Store',
                'material' => 'Inventory', 'materials' => 'Inventory',
                'stock' => 'Inventory', 'stocks' => 'Inventory',
                'recipe' => 'Inventory', 'recipes' => 'Inventory',
                'promotion' => 'Promotions', 'promotions' => 'Promotions',
                'promotion_template' => 'PromotionTemplates', 'promotion_templates' => 'PromotionTemplates',
                'settlement_rule' => 'Settlement', 'settlement_rules' => 'Settlement',
                'settlement_rule_version' => 'Settlement', 'settlement_rule_versions' => 'Settlement',
                'settlement_plan' => 'Settlement', 'settlement_plans' => 'Settlement',
                'settlement_allocation' => 'Settlement', 'settlement_allocations' => 'Settlement',
                'settlement_outbox_message' => 'Settlement', 'settlement_outbox_messages' => 'Settlement',
                'settlement_consumed_event' => 'Settlement', 'settlement_consumed_events' => 'Settlement',
                'user' => 'Auth', 'users' => 'Auth',
                'profile' => 'Auth', 'profiles' => 'Auth',
                'wechat_user' => 'Wechat', 'wechat_users' => 'Wechat',
                'assignment' => 'Authorization', 'assignments' => 'Authorization',
                'role' => 'Authorization', 'roles' => 'Authorization',
                'permission' => 'Authorization', 'permissions' => 'Authorization',
                'audit_log' => 'Authorization', 'audit_logs' => 'Authorization', 'audit' => 'Authorization',
                'role_field_grant' => 'Authorization', 'role_field_grants' => 'Authorization',
                'field_grant' => 'Authorization', 'field_grants' => 'Authorization',
            ];
            if (isset($known[$resource])) return $known[$resource];

            // Unknown resource — auto-title-case
            return str_replace('_', ' ', ucfirst($resource));
        }

        return null;
    }

    /**
     * Ensure dynamically detected tags appear in the spec's tag list.
     * @param array<mixed, array<string, string>> $tags
     */
    private function ensureTag(array &$tags, string $name): void
    {
        foreach ($tags as $t) {
            if ($t['name'] === $name) return;
        }
        $tags[] = ['name' => $name, 'description' => ''];
    }
}
