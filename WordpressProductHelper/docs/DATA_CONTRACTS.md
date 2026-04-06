# Data Contracts

## Intake payload (normalized)
```php
[
    'title_hint'   => '',
    'notes'        => '',
    'price'        => '',
    'sku'          => '',
    'stock_status' => 'instock',
    'image_ids'    => [],
]
```

## AI result payload (normalized)
```php
[
    'title'               => '',
    'description'         => '',
    'short_description'   => '',
    'tags'                => [],
    'category_suggestion' => '',
    'missing_fields'      => [],
]
```

## Stored draft payload
```php
[
    'draft_id'            => '',
    'created_by'          => 0,
    'created_at'          => 0,
    'intake'              => [],
    'ai'                  => [],
    'selected_image_ids'  => [],
]
```

## Final review payload before product creation
```php
[
    'draft_token'         => '',
    'title'               => '',
    'description'         => '',
    'short_description'   => '',
    'tags'                => [],
    'category_id'         => 0,
    'price'               => '',
    'sku'                 => '',
    'stock_status'        => 'instock',
    'image_ids'           => [],
]
```

## Stock status allowlist
- `instock`
- `outofstock`
- `onbackorder`
