# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Setup and Installation
```bash
./setup.sh          # Initial setup with Docker and Composer dependencies
```

### Testing
```bash
./run-tests.sh                    # Run PHPUnit tests in Docker container
composer test                     # Alternative: run tests directly via Composer script
vendor/bin/phpunit                # Run tests directly (requires local PHP 8.4)
vendor/bin/phpunit tests/SpecificTest.php  # Run a specific test file
```

### Dependencies
```bash
# Install dependencies (done by setup.sh)
docker run --rm -v "$(pwd)":/app -w /app php:8.4-cli sh -c "composer install --ignore-platform-reqs"
```

## Architecture Overview

This is a high-performance PHP cache library implementing the **stale-while-revalidate** pattern with cache stampede prevention. The architecture follows these key principles:

### Core Components

1. **Cache.php** - Main cache implementation with leader/follower pattern
   - Implements stale-while-revalidate with precompute windows
   - Uses Stash library locks for single-flight cache fills
   - Supports both sync and async operations via event dispatching

2. **Key System** - Structured cache keys with domain/facet/id hierarchy
   - `Key.php` - Main key implementation with versioning and localization
   - Supports hierarchical invalidation via prefixes

3. **Async Operations** - Event-driven background processing
   - `AsyncEvent.php` - Events for async invalidation/refresh
   - `AsyncHandler.php` - Processes async events
   - `SyncMode.php` - Enum for sync vs async operation modes

4. **Interfaces** - Well-defined contracts in `Interface/` directory
   - `CacheInterface` - Main cache operations
   - `LoaderInterface` - Data loading strategy
   - `JitterInterface` - Stampede prevention via randomized TTLs
   - `MetricsInterface` - Performance monitoring
   - `KeyInterface` & `KeyPrefixInterface` - Cache key contracts
   - `ValueResultInterface` - Cache result wrapper
   - `PsrPoolAccessInterface` - PSR cache pool access

5. **Support Components** - Helper classes and utilities
   - `CallableLoader.php` - Simple function-based data loader
   - `DefaultJitter.php` - Standard jitter implementation
   - `CacheReadState.php` - Cache state enumeration
   - `ValueResult.php` - Result wrapper with metadata
   - `MyRedisDriver.php` & `MyItem.php` - Custom Stash implementations

### Cache Behavior

The cache implements a sophisticated multi-tier access pattern:

1. **Fresh Hit** - Serve cached data within precompute window
2. **Leader Path** - Winner of lock recomputes and saves fresh data
3. **Follower Stale** - Serve stale data while leader recomputes
4. **Follower Wait** - Brief wait for leader, then serve fresh
5. **Fail-Open** - Compute without caching as last resort

### Dependencies

- **PHP 8.4+** required
- **tedivm/stash v1.2.1** - Core caching with Redis support
- **ext-redis** - Redis extension for production backend
- **PSR interfaces** - Event dispatching and logging

### Configuration Notes

- Uses Docker for consistent PHP 8.4 environment
- Tests directory exists with PHPUnit configuration in `phpunit.xml`
- Example usage available in `src/Example/index.php`
- Project uses Composer autoloading with PSR-4 standard (`Cache\` namespace)
- Comprehensive architecture documentation in `ARCHITECTURAL_ANALYSIS.md`
- Project is currently in prototype/MVP phase, see analysis document for production roadmap

### Development Patterns

When working with this codebase:
- All cache operations should use structured `Key` objects with domain/facet/id hierarchy
- Prefer async operations for invalidation/refresh to avoid blocking
- Use the jitter system to prevent cache stampedes
- Implement `LoaderInterface` for custom data sources (see `CallableLoader` for simple cases)
- Follow the existing interface contracts for extensibility
- Use `src/Example/index.php` as reference for basic usage patterns
- Test cache behavior with Docker environment to ensure PHP 8.4 compatibility
- Reference `ARCHITECTURAL_ANALYSIS.md` for deeper understanding of cache theory and implementation