@component('mail::message')
# Low Stock Alert

**ATTENTION:** A product in your inventory has fallen below the minimum stock threshold.

## Product Details

**Product Name:** {{ $product->name }}  
**SKU:** {{ $product->sku }}  
**Current Stock:** {{ $product->stock_quantity }}  
**Threshold:** {{ $threshold }}

@if($product->stock_quantity <= 0)
**This product is now OUT OF STOCK and unavailable for purchase.**
@else
**This product is running low and may become unavailable soon.**
@endif

@component('mail::button', ['url' => config('app.frontend_url') . '/admin/products/' . $product->id . '/edit'])
Manage Product
@endcomponent

Please take action to restock this item if needed.

Regards,<br>
{{ config('app.name') }} System
@endcomponent
