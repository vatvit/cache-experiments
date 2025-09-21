# Async Operations and Event Flow

## Overview

The cache library implements a dual-mode operation system supporting both synchronous and asynchronous execution patterns. The async system leverages PSR-14 event dispatchers to decouple cache operations from their execution timing, enabling better performance and system responsiveness.

## Architecture Components

### Core Components

```
Async System Architecture:
┌─────────────────────────────────────────────────────────────────┐
│                    Cache (Main Interface)                       │
│  ┌───────────────┐ ┌───────────────┐ ┌───────────────────────┐  │
│  │   invalidate  │ │    refresh    │ │   invalidateExact     │  │
│  │    (prefix)   │ │    (reload)   │ │     (specific)        │  │
│  └───────────────┘ └───────────────┘ └───────────────────────┘  │
└─────────────────┬───────────────────────────────────────────────┘
                  │ SyncMode decision
        ┌─────────┼─────────┐
        │ SYNC    │         │ ASYNC
        ▼         │         ▼
┌─────────────┐   │   ┌─────────────────┐
│ Direct      │   │   │ EventDispatcher │
│ Execution   │   │   │ (PSR-14)        │
│             │   │   └─────────────────┘
└─────────────┘   │           │
                  │           ▼
                  │   ┌─────────────────┐
                  │   │   AsyncEvent    │
                  │   │   (Payload)     │
                  │   └─────────────────┘
                  │           │
                  │           ▼
                  │   ┌─────────────────┐
                  │   │  AsyncHandler   │
                  │   │  (Processor)    │
                  │   └─────────────────┘
                  │           │
                  └───────────┘
                              ▼
                      ┌─────────────────┐
                      │ Cache Operation │
                      │ (SYNC mode)     │
                      └─────────────────┘
```

## Sync vs Async Execution Modes

### SyncMode Enum

```php
enum SyncMode {
    case SYNC;   // Immediate execution
    case ASYNC;  // Event-driven execution
}
```

### Mode Selection Impact

#### Synchronous Mode (SYNC)
```
Synchronous Execution Flow:
┌─────────────────────────────────────────┐
│ Client Call:                            │
│ cache.invalidate(key, SyncMode::SYNC)   │
└─────────────────────────────────────────┘
                  │ immediate
                  ▼
┌─────────────────────────────────────────┐
│ Direct Cache Operation:                 │
│ pool.getDriver().clear(selector)        │
│ metrics.inc('cache_invalidate_hierarchical') │
└─────────────────────────────────────────┘
                  │ synchronous
                  ▼
┌─────────────────────────────────────────┐
│ Redis Operation Completes               │
│ Function Returns                        │
└─────────────────────────────────────────┘
```

**Characteristics:**
- **Latency**: Blocks until Redis operation completes
- **Reliability**: Immediate confirmation of completion
- **Error Handling**: Direct exception propagation
- **Use Cases**: Critical operations requiring immediate consistency

#### Asynchronous Mode (ASYNC)
```
Asynchronous Execution Flow:
┌─────────────────────────────────────────┐
│ Client Call:                            │
│ cache.invalidate(key, SyncMode::ASYNC)  │
└─────────────────────────────────────────┘
                  │ immediate return
                  ▼
┌─────────────────────────────────────────┐
│ Event Creation & Dispatch:              │
│ event = new AsyncEvent(selector, false) │
│ eventDispatcher.dispatch(event)         │
│ return; // Function completes           │
└─────────────────────────────────────────┘
                  │ background processing
                  ▼
┌─────────────────────────────────────────┐
│ Event Handler (Later):                  │
│ asyncHandler.handleInvalidation(event)  │
│ cache.invalidate(key, SyncMode::SYNC)   │
└─────────────────────────────────────────┘
```

**Characteristics:**
- **Latency**: Immediate return (~1ms)
- **Reliability**: Eventually consistent
- **Error Handling**: Requires separate error tracking
- **Use Cases**: High-throughput operations, non-critical invalidations

## Event System Design

### AsyncEvent Structure

```php
class AsyncEvent {
    public function __construct(
        public KeyInterface $key,      // Target key or prefix
        public bool $exact = false,    // Exact vs hierarchical
    ) {}
}
```

### Event Types and Payloads

#### 1. Hierarchical Invalidation Event
```php
// Invalidates all keys matching prefix
$event = new AsyncEvent(
    key: $keyPrefix,
    exact: false  // Hierarchical mode
);

// Example: Invalidate all user profiles
// key = "user/profile/" (prefix)
// exact = false
```

#### 2. Exact Invalidation Event
```php
// Invalidates specific key only
$event = new AsyncEvent(
    key: $specificKey,
    exact: true  // Exact mode
);

// Example: Invalidate specific user
// key = "user/profile/v2/en-US/12345" (full key)
// exact = true
```

#### 3. Refresh Event
```php
// Refresh/reload specific key
$event = new AsyncEvent(
    key: $specificKey,
    exact: false  // exact flag ignored for refresh
);

// Example: Reload user profile
// key = "user/profile/v2/en-US/12345"
// Operation: loader.resolve() + cache.put()
```

## AsyncHandler Implementation

### Handler Structure

```php
class AsyncHandler {
    public function __construct(
        private Cache $cache,
    ) {}

    public function handleInvalidation(AsyncEvent $event): void
    public function handleRefresh(AsyncEvent $event): void
}
```

### Event Processing Flow

#### Invalidation Handler
```
Event Processing: Invalidation
┌─────────────────────────────────────────┐
│ AsyncHandler.handleInvalidation()       │
│                                         │
│ if (event.exact) {                      │
│   cache.invalidateExact(                │
│     event.key, SyncMode::SYNC           │
│   )                                     │
│ } else {                                │
│   cache.invalidate(                     │
│     event.key, SyncMode::SYNC           │
│   )                                     │
│ }                                       │
└─────────────────────────────────────────┘
```

#### Refresh Handler
```
Event Processing: Refresh
┌─────────────────────────────────────────┐
│ AsyncHandler.handleRefresh()            │
│                                         │
│ cache.refresh(                          │
│   event.key, SyncMode::SYNC             │
│ )                                       │
│                                         │
│ // Internally:                          │
│ // 1. loaded = loader.resolve(key)      │
│ // 2. cache.put(key, loaded)            │
└─────────────────────────────────────────┘
```

## Event Dispatcher Integration

### PSR-14 Compliance

```php
// Interface compliance
interface EventDispatcherInterface {
    public function dispatch(object $event): object;
}

// Usage in Cache class
private EventDispatcherInterface|null $eventDispatcher = null;

// Event dispatch
$this->eventDispatcher->dispatch(new AsyncEvent($selector, false));
```

### Event Listener Registration

```php
// Example event listener setup (framework-dependent)
$eventDispatcher->listen(AsyncEvent::class, function (AsyncEvent $event) {
    $asyncHandler = $container->get(AsyncHandler::class);

    // Determine operation type from context or additional event properties
    if ($event->isRefresh()) {
        $asyncHandler->handleRefresh($event);
    } else {
        $asyncHandler->handleInvalidation($event);
    }
});
```

## Operation Type Patterns

### Cache Method to Event Type Mapping

```
Cache Operation → Event Flow:

invalidate(prefix, ASYNC)
├─ Event: AsyncEvent(prefix, exact: false)
└─ Handler: handleInvalidation() → invalidate(prefix, SYNC)

invalidateExact(key, ASYNC)
├─ Event: AsyncEvent(key, exact: true)
└─ Handler: handleInvalidation() → invalidateExact(key, SYNC)

refresh(key, ASYNC)
├─ Event: AsyncEvent(key, exact: false)
└─ Handler: handleRefresh() → refresh(key, SYNC)
```

### Event Handler Decision Tree

```
AsyncEvent Processing Decision:
┌─────────────────────────────────────────┐
│ Receive AsyncEvent                      │
└─────────────────┬───────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────┐
│ Determine Operation Type:               │
│                                         │
│ if (isRefreshOperation(event)) {        │
│   handleRefresh(event);                 │
│ } else {                                │
│   handleInvalidation(event);            │
│ }                                       │
└─────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────┐
│ Execute with SyncMode::SYNC             │
│ (Prevents infinite async loops)         │
└─────────────────────────────────────────┘
```

## Performance Characteristics

### Async Performance Benefits

#### Latency Comparison
```
Operation Latency Analysis:
┌─────────────────────────────────────────┐
│ Sync Mode:                              │
│ ├─ invalidate(): 5-50ms (Redis op)     │
│ ├─ refresh(): 100-1000ms (load+save)   │
│ └─ Total request: includes cache time   │
│                                         │
│ Async Mode:                             │
│ ├─ dispatch(): <1ms (event creation)   │
│ ├─ return: immediate                    │
│ └─ background: 5-1000ms (eventual)     │
└─────────────────────────────────────────┘
```

#### Throughput Impact
```
Throughput Comparison (requests/second):
┌─────────────────────────────────────────┐
│ Sync Invalidation:                      │
│ ├─ Limited by Redis operation time     │
│ └─ ~200-2000 ops/sec                   │
│                                         │
│ Async Invalidation:                     │
│ ├─ Limited by event dispatch speed     │
│ └─ ~10,000-50,000 ops/sec              │
└─────────────────────────────────────────┘
```

### Event Processing Patterns

#### Queue-Based Processing
```
Event Queue Architecture:
┌─────────────────────────────────────────┐
│ High-Frequency Events:                  │
│ ├─ event1: invalidate("user/profile/")  │
│ ├─ event2: invalidate("user/profile/")  │ ← Duplicate
│ ├─ event3: refresh("user/profile/123")  │
│ └─ event4: invalidate("user/profile/")  │ ← Duplicate
│                                         │
│ Optimization Opportunities:             │
│ ├─ Deduplication: Merge duplicate ops  │
│ ├─ Batching: Group similar operations  │
│ └─ Coalescing: Combine overlapping ops │
└─────────────────────────────────────────┘
```

## Error Handling and Reliability

### Async Error Scenarios

#### Event Dispatch Failures
```php
try {
    $this->eventDispatcher->dispatch(new AsyncEvent($selector, false));
} catch (EventDispatchException $e) {
    // Fallback to sync mode
    $this->invalidate($selector, SyncMode::SYNC);

    // Log the event dispatch failure
    $this->logger->warning('Event dispatch failed, falling back to sync', [
        'selector' => $selector,
        'error' => $e->getMessage()
    ]);
}
```

#### Handler Processing Failures
```php
public function handleInvalidation(AsyncEvent $event): void
{
    try {
        if ($event->exact) {
            $this->cache->invalidateExact($event->key, SyncMode::SYNC);
        } else {
            $this->cache->invalidate($event->key, SyncMode::SYNC);
        }
    } catch (CacheException $e) {
        // Log error but don't throw (async context)
        $this->logger->error('Async invalidation failed', [
            'key' => $event->key->toString(),
            'exact' => $event->exact,
            'error' => $e->getMessage()
        ]);

        // Optionally: retry logic, dead letter queue, etc.
    }
}
```

### Reliability Patterns

#### At-Least-Once Delivery
```
Event Reliability Strategy:
┌─────────────────────────────────────────┐
│ 1. Immediate Event Dispatch            │
│ 2. Persistent Queue (optional)         │
│ 3. Retry on Failure                    │
│ 4. Dead Letter Queue                   │
│ 5. Monitoring & Alerting               │
└─────────────────────────────────────────┘
```

#### Eventually Consistent Operations
```php
// Design for eventual consistency
class CacheInvalidationTracker
{
    public function trackInvalidation(KeyInterface $key, bool $exact): void
    {
        // Record pending invalidation
        $this->pendingInvalidations->add($key, $exact, time());
    }

    public function confirmInvalidation(KeyInterface $key, bool $exact): void
    {
        // Mark as completed
        $this->pendingInvalidations->remove($key, $exact);
    }

    public function getPendingInvalidations(): array
    {
        // Return operations older than threshold
        return $this->pendingInvalidations->olderThan(30); // 30 seconds
    }
}
```

## Monitoring and Observability

### Key Metrics

#### Async Performance Metrics
```php
// Event dispatch metrics
'async_event_dispatched' => ['operation' => 'invalidate|refresh']
'async_event_processed' => ['operation' => 'invalidate|refresh', 'status' => 'success|error']
'async_event_latency' => ['operation' => 'invalidate|refresh'] // processing time

// Mode distribution
'cache_operation_mode' => ['mode' => 'sync|async', 'operation' => 'invalidate|refresh']

// Error tracking
'async_event_error' => ['operation' => 'invalidate|refresh', 'error_type' => '...']
```

#### Queue Health Metrics
```php
// Queue depth and processing
'async_queue_depth' => [] // pending events
'async_queue_age' => [] // oldest pending event
'async_throughput' => [] // events processed per second

// Reliability metrics
'async_retry_count' => ['operation' => 'invalidate|refresh']
'async_dead_letter' => ['operation' => 'invalidate|refresh']
```

### Alerting Thresholds

```
Critical Alerts:
├─ async_queue_depth > 1000 events
├─ async_queue_age > 60 seconds
├─ async_event_error rate > 5%
└─ async_throughput < expected baseline

Warning Alerts:
├─ async_queue_depth > 100 events
├─ async_queue_age > 10 seconds
└─ sync_fallback rate > 1%
```

## Best Practices

### When to Use Async Mode
1. **High-Volume Invalidations**: Bulk user session cleanup
2. **Non-Critical Operations**: Content recommendation updates
3. **Performance-Sensitive Paths**: API response optimization
4. **Background Maintenance**: Scheduled cache warming

### When to Use Sync Mode
1. **Critical Consistency**: User authentication changes
2. **Immediate Feedback**: User-triggered data updates
3. **Error-Sensitive Operations**: Financial transaction cache
4. **System Operations**: Health checks, debugging

### Implementation Guidelines
1. **Default to Async**: Use async mode unless sync required
2. **Monitor Queue Health**: Track processing delays
3. **Implement Fallbacks**: Sync mode for event failures
4. **Test Both Modes**: Verify behavior under load
5. **Document Consistency**: Clear expectations for eventual consistency