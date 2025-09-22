# PHP Cache Library - High-Level Architecture

## System Overview

The PHP cache library implements a sophisticated stale-while-revalidate caching strategy with leader/follower coordination to prevent cache stampedes. The system is built on top of the Stash library and provides both synchronous and asynchronous operation modes.

## Component Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              Client Application                                  │
└─────────────────────────────┬───────────────────────────────────────────────────┘
                              │ get/put/invalidate/refresh
                              ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                            Cache (Main Facade)                                  │
│  ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐                   │
│  │   CacheInterface │ │ PsrPoolAccess   │ │ Metrics/Events  │                   │
│  │                 │ │ Interface       │ │ Integration     │                   │
│  └─────────────────┘ └─────────────────┘ └─────────────────┘                   │
└─────────────────────────────┬───────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
        ▼                     ▼                     ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│  LoaderInterface│ │  StashPool      │ │ EventDispatcher │
│                 │ │  (Redis/File)   │ │ (PSR-14)        │
└─────────────────┘ └─────────────────┘ └─────────────────┘
        │                     │                     │
        │                     ▼                     ▼
        │           ┌─────────────────┐ ┌─────────────────┐
        │           │  MyRedisDriver  │ │   AsyncHandler  │
        │           │                 │ │                 │
        │           └─────────────────┘ └─────────────────┘
        │                     │                     │
        ▼                     ▼                     ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│ Business Logic  │ │     Redis       │ │  AsyncEvent     │
│ Data Sources    │ │    Backend      │ │  Processing     │
└─────────────────┘ └─────────────────┘ └─────────────────┘
```

## Core Components

### 1. Cache (Primary Interface)
- **Role**: Main facade and orchestrator
- **Responsibilities**:
  - Implements 5-tier cache resolution strategy
  - Coordinates leader/follower patterns
  - Manages TTL and soft expiration windows
  - Integrates with metrics and event systems
- **Dependencies**: StashPool, LoaderInterface, JitterInterface, EventDispatcher

### 2. Key System
- **Role**: Hierarchical cache key management
- **Components**:
  - `Key`: Concrete implementation with domain/facet/version/locale/id structure
  - `KeyInterface`: Abstraction for cache keys
  - `KeyPrefixInterface`: Hierarchical invalidation support
- **Features**:
  - URL-encoded key segments
  - Base64-encoded composite IDs
  - Hierarchical prefix support for bulk operations

### 3. Value Result System
- **Role**: Rich cache response metadata
- **Components**:
  - `ValueResult`: Immutable result wrapper
  - `CacheReadState`: Enum (HIT/STALE/MISS)
- **Features**:
  - Distinguishes fresh vs stale hits
  - Provides creation and expiration timestamps
  - Type-safe state management

### 4. Async Event System
- **Role**: Asynchronous cache operations
- **Components**:
  - `AsyncEvent`: Event payload for invalidation/refresh
  - `AsyncHandler`: Event processor
  - `SyncMode`: Enum for operation modes
- **Features**:
  - PSR-14 event dispatcher integration
  - Async invalidation and refresh
  - Exact vs hierarchical invalidation modes

## External Dependencies

### Stash Library Integration
- **StashPoolInterface**: Core caching backend
- **ItemInterface**: Individual cache items with locking
- **MyRedisDriver**: Custom Redis driver implementation
- **Invalidation Modes**: PRECOMPUTE, OLD, SLEEP strategies

### PSR Standards
- **PSR-6**: Cache Item Pool Interface compliance
- **PSR-14**: Event Dispatcher Interface
- **PSR-16**: Simple Cache (via adapter pattern)

## Data Flow

1. **Request Flow**: Client → Cache → Key Resolution → StashPool → Redis
2. **Response Flow**: Redis → StashPool → ValueResult → Client
3. **Event Flow**: Cache → EventDispatcher → AsyncHandler → Cache (sync mode)
4. **Invalidation Flow**: Hierarchical prefix matching → Redis pattern clearing

## Architecture Principles

### Separation of Concerns
- Cache coordination logic separated from storage
- Key management isolated from cache operations
- Event handling decoupled from core cache logic

### Dependency Injection
- All dependencies injected via constructor
- Interface-based design for testability
- Optional dependencies (metrics, events) supported

### Performance Optimization
- Lock-based leader/follower coordination
- Stale-while-revalidate prevents cache stampedes
- Jitter support for TTL randomization
- Hierarchical invalidation for efficient bulk operations

### Observability
- Comprehensive metrics integration
- Event-driven architecture for monitoring
- Rich result metadata for debugging