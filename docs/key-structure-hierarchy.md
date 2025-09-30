# Key Structure and Hierarchical Invalidation

## Overview

The cache key system implements a hierarchical structure that enables both precise cache targeting and efficient bulk invalidation operations. Keys are composed of multiple segments that form a natural hierarchy for organizing cached data.

## Key Structure Anatomy

### Core Components

```
Complete Key Structure:
┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐
│ domain  │ │  facet  │ │ version │ │ locale  │ │   id    │
└─────────┘ └─────────┘ └─────────┘ └─────────┘ └─────────┘
     │           │           │           │           │
     └───────────┼───────────┼───────────┼───────────┘
                 │           │           │
              Prefix Components      Identifier
            (Hierarchical Scope)   (Specific Item)
```

### Segment Definitions

#### Required Segments
- **Domain**: Top-level namespace (e.g., "user", "product", "content")
- **Facet**: Data type or operation (e.g., "profile", "search", "recommendations")
- **ID**: Specific identifier (string, int, or composite array)

#### Optional Segments
- **Schema Version**: Data format version (e.g., "v1", "v2", "2024-03-15")
- **Locale**: Internationalization context (e.g., "en-US", "de-DE", "zh-CN")

## Key Construction Process

### 1. Input Normalization

```php
Input Validation and Normalization:
┌─────────────────────────────────────────┐
│ Raw Input:                              │
│   domain: "  User Profile  "            │
│   facet: "recommendations"              │
│   id: ["userId" => 123, "type" => "ai"] │
│   version: "v2"                         │
│   locale: "en-US"                       │
└─────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────┐
│ Normalized:                             │
│   domain: "User Profile"                │
│   facet: "recommendations"              │
│   id: ["type" => "ai", "userId" => 123] │ ← Sorted
│   version: "v2"                         │
│   locale: "en-US"                       │
└─────────────────────────────────────────┘
```

### 2. ID Processing

#### Simple ID (String/Int)
```
Input: 12345
Output: "12345"
```

#### Composite ID (Array)
```
Array ID Processing:
┌─────────────────────────────────────────┐
│ Input Array:                            │
│ ["userId" => 123, "type" => "ai"]       │
└─────────────────────────────────────────┘
                  │
                  ▼ ksort() + recursive normalization
┌─────────────────────────────────────────┐
│ Normalized Array:                       │
│ ["type" => "ai", "userId" => 123]       │
└─────────────────────────────────────────┘
                  │
                  ▼ JSON encode + base64url
┌─────────────────────────────────────────┐
│ Encoded String:                         │
│ "j:eyJ0eXBlIjoiYWkiLCJ1c2VySWQiOjEyM30" │
│  │ │                                    │
│  │ └─ Base64URL-encoded JSON            │
│  └─── "j:" prefix for JSON type         │
└─────────────────────────────────────────┘
```

### 3. Segment Encoding

```
URL Encoding Process:
┌─────────────────────────────────────────┐
│ Raw Segments:                           │
│ ["User Profile", "recommendations",     │
│  "v2", "en-US", "j:eyJ0eXBlIj..."]     │
└─────────────────────────────────────────┘
                  │ rawurlencode() each segment
                  ▼
┌─────────────────────────────────────────┐
│ Encoded Segments:                       │
│ ["User%20Profile", "recommendations",   │
│  "v2", "en-US", "j%3AeyJ0eXBlIj..."]   │
└─────────────────────────────────────────┘
                  │ implode with '/'
                  ▼
┌─────────────────────────────────────────┐
│ Final Key:                              │
│ "User%20Profile/recommendations/v2/     │
│  en-US/j%3AeyJ0eXBlIj..."              │
└─────────────────────────────────────────┘
```

## Hierarchical Structure

### Prefix Hierarchy

```
Key Hierarchy (Most General → Most Specific):

Level 1: domain/
├─ "user/"
├─ "product/"
└─ "content/"

Level 2: domain/facet/
├─ "user/profile/"
├─ "user/preferences/"
├─ "product/catalog/"
└─ "product/reviews/"

Level 3: domain/facet/version/
├─ "user/profile/v1/"
├─ "user/profile/v2/"
└─ "product/catalog/v3/"

Level 4: domain/facet/version/locale/
├─ "user/profile/v2/en-US/"
├─ "user/profile/v2/de-DE/"
└─ "product/catalog/v3/en-US/"

Level 5: domain/facet/version/locale/id
├─ "user/profile/v2/en-US/12345"
├─ "user/profile/v2/en-US/67890"
└─ "product/catalog/v3/en-US/abc123"
```

### Prefix Extraction

```php
Key Analysis:
┌─────────────────────────────────────────────────────────┐
│ Full Key: "user/profile/v2/en-US/12345"                 │
│                                                         │
│ Extracted Components:                                   │
│ ┌─────────────────────────────────────────────────────┐ │
│ │ prefixSegments(): ["user", "profile", "v2", "en-US"]│ │
│ │ fullSegments():   ["user", "profile", "v2", "en-US", │ │
│ │                    "12345"]                         │ │
│ │ prefixString():   "user/profile/v2/en-US"           │ │
│ │ toString():       "user/profile/v2/en-US/12345"     │ │
│ └─────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘
```

## Invalidation Patterns

### Exact Invalidation

```
Target: Specific cache entry
┌─────────────────────────────────────────┐
│ invalidateExact("user/profile/v2/en-US/12345") │
│                                         │
│ Affects: ✓ user/profile/v2/en-US/12345  │
│         ✗ user/profile/v2/en-US/67890  │
│         ✗ user/profile/v2/de-DE/12345  │
└─────────────────────────────────────────┘
```

### Hierarchical Invalidation

```
Target: All entries matching prefix
┌─────────────────────────────────────────┐
│ invalidate("user/profile/v2/")          │
│                                         │
│ Affects: ✓ user/profile/v2/en-US/12345  │
│         ✓ user/profile/v2/en-US/67890  │
│         ✓ user/profile/v2/de-DE/12345  │
│         ✓ user/profile/v2/fr-FR/99999  │
│         ✗ user/preferences/v1/en-US/123│
│         ✗ product/catalog/v3/en-US/abc │
└─────────────────────────────────────────┘
```

### Invalidation Scope Examples

#### 1. Domain-Level Invalidation
```
invalidate("user/")
└─ Clears ALL user-related cache entries
   ├─ user/profile/*
   ├─ user/preferences/*
   ├─ user/sessions/*
   └─ user/notifications/*
```

#### 2. Facet-Level Invalidation
```
invalidate("user/profile/")
└─ Clears ALL user profile cache entries
   ├─ user/profile/v1/*
   ├─ user/profile/v2/*
   └─ All locales and IDs within
```

#### 3. Version-Level Invalidation
```
invalidate("user/profile/v2/")
└─ Clears ALL v2 user profiles
   ├─ user/profile/v2/en-US/*
   ├─ user/profile/v2/de-DE/*
   └─ All IDs within each locale
```

#### 4. Locale-Level Invalidation
```
invalidate("user/profile/v2/en-US/")
└─ Clears ALL English v2 user profiles
   ├─ user/profile/v2/en-US/12345
   ├─ user/profile/v2/en-US/67890
   └─ All other English user IDs
```

## Advanced Use Cases

### Multi-Version Cache Management

```
Scenario: API Version Migration
┌─────────────────────────────────────────┐
│ Old Version Cache:                      │
│ ├─ user/profile/v1/en-US/12345         │
│ ├─ user/profile/v1/en-US/67890         │
│ └─ user/profile/v1/de-DE/12345         │
│                                         │
│ New Version Cache:                      │
│ ├─ user/profile/v2/en-US/12345         │
│ ├─ user/profile/v2/en-US/67890         │
│ └─ user/profile/v2/de-DE/12345         │
│                                         │
│ Migration Strategy:                     │
│ 1. invalidate("user/profile/v1/")       │
│ 2. Gradual refresh of v2 cache         │
└─────────────────────────────────────────┘
```

### Locale-Specific Invalidation

```
Scenario: Content Translation Update
┌─────────────────────────────────────────┐
│ Content Changed: German translations    │
│                                         │
│ Invalidation Strategy:                  │
│ invalidate("content/articles/v1/de-DE/")│
│                                         │
│ Affected:                               │
│ ├─ content/articles/v1/de-DE/news123   │
│ ├─ content/articles/v1/de-DE/blog456   │
│ └─ content/articles/v1/de-DE/help789   │
│                                         │
│ Preserved:                              │
│ ├─ content/articles/v1/en-US/* (all)   │
│ ├─ content/articles/v1/fr-FR/* (all)   │
│ └─ content/videos/v1/de-DE/* (different facet) │
└─────────────────────────────────────────┘
```

### Composite ID Scenarios

#### User-Specific Filtered Data
```php
// Cache key for user's AI recommendations
$key = new Key(
    domain: "recommendations",
    facet: "personalized",
    id: [
        "userId" => 12345,
        "algorithm" => "collaborative_filtering",
        "contentType" => "articles",
        "maxResults" => 50
    ],
    version: "v3",
    locale: "en-US"
);

// Resulting hierarchy enables targeted invalidation:
// - All recommendations: invalidate("recommendations/")
// - All personalized: invalidate("recommendations/personalized/")
// - All v3 personalized: invalidate("recommendations/personalized/v3/")
// - All English: invalidate("recommendations/personalized/v3/en-US/")
```

## Performance Considerations

### Key Length Optimization
- **Encoded Composite IDs**: Base64 encoding adds ~33% overhead
- **URL Encoding**: Minimal impact for ASCII characters
- **Hierarchy Depth**: Balance granularity vs. key length

### Redis Pattern Matching
- **Prefix Invalidation**: Uses Redis `SCAN` with pattern matching
- **Wildcard Patterns**: Efficient for hierarchical clearing
- **Index Overhead**: Each level adds namespace organization

### Cache Distribution
```
Key Distribution Analysis:
┌─────────────────────────────────────────┐
│ Domain Distribution:                    │
│ ├─ user/*         : 60% of keys        │
│ ├─ product/*      : 25% of keys        │
│ ├─ content/*      : 10% of keys        │
│ └─ system/*       : 5% of keys         │
│                                         │
│ Invalidation Impact:                    │
│ ├─ Domain-level   : High impact        │
│ ├─ Facet-level    : Medium impact      │
│ ├─ Version-level  : Low impact         │
│ └─ Exact-level    : Minimal impact     │
└─────────────────────────────────────────┘
```

## Best Practices

### Key Design Guidelines
1. **Domain Organization**: Group related functionality
2. **Facet Specificity**: Clear data type separation
3. **Version Strategy**: Plan for schema evolution
4. **Locale Handling**: Consistent i18n approach
5. **ID Composition**: Logical grouping for composite keys

### Invalidation Strategy
1. **Precision First**: Use exact invalidation when possible
2. **Hierarchical Fallback**: Leverage prefix matching for bulk operations
3. **Version Awareness**: Consider cross-version impacts
4. **Performance Monitoring**: Track invalidation scope and frequency
