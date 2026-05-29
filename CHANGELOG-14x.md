# Changelog

All notable changes to this project will be documented in this file.

## [unreleased](https://github.com/websolutionfalcon/laravel-queue-rabbitmq/compare/v14.1.0...master)

## [14.1.0] - 2026-05-29

### Added
- **Laravel 13 & PHP 8.5 support** (upstream [#652](https://github.com/vyuldashev/laravel-queue-rabbitmq/pull/652)): version constraints now allow `^13.0` for `illuminate/queue` and `laravel/framework`, plus `^12.0` phpunit and `^11.0` orchestra/testbench
- **Queue metrics methods** on `RabbitMQQueue`: `pendingSize()`, `delayedSize()`, `reservedSize()`, `creationTimeOfOldestPendingJob()` for Laravel queue monitoring compatibility
- `Consumer::stop()` now accepts an optional `$reason` parameter

### Fixed
- Send `JobPending` event on push and when delayed (upstream [#657](https://github.com/vyuldashev/laravel-queue-rabbitmq/pull/657))
- `Consumer::$currentJob` visibility error under Laravel 13.7 (upstream [#660](https://github.com/vyuldashev/laravel-queue-rabbitmq/pull/660))
- Scope `$currentJob` to `daemon()` to fix a false-sleep throughput regression (upstream [#665](https://github.com/vyuldashev/laravel-queue-rabbitmq/pull/665))

## [14.0.0] - 2026-01-14

### Added
- **Delayed Message Exchange Plugin Support**: Native support for `rabbitmq_delayed_message_exchange` plugin
- **Strategy Pattern**: Choose between DLX (default) or Plugin strategies for delayed messages
- **Configuration Options**:
  - `delay_strategy`: 'dlx' or 'plugin' (default: 'dlx')
  - `delayed_exchange`: Exchange name for plugin strategy
  - `delayed_exchange_type`: Underlying exchange type (direct, topic, fanout, headers)
- **Automatic Fallback**: Plugin strategy automatically falls back to DLX if plugin unavailable
- **Consumer Auto-Declaration**: `declareConsumerDestination()` method ensures queues exist before consuming (fixes PR #646)
- **Lazy Plugin Detection**: Plugin detection deferred until first delayed message to avoid interference
- **Strategy Classes**:
  - `DelayStrategyInterface`: Contract for delay strategies
  - `AbstractDelayStrategy`: Base class with shared functionality
  - `DLXDelayStrategy`: Default Dead Letter Exchange strategy
  - `PluginDelayStrategy`: Plugin-based strategy
  - `PluginDelayStrategyWithFallback`: Lazy fallback wrapper
  - `DelayStrategyFactory`: Factory for creating strategies
- **Comprehensive Testing**:
  - 17 new unit tests for strategies
  - 10 new functional tests verifying actual RabbitMQ flow
  - 4 new unit tests for consumer auto-declaration
  - RabbitMQ Management API integration using Guzzle
- **Documentation**:
  - Comprehensive "Delayed Messages" section in README
  - Plugin installation instructions for RabbitMQ 3.x and 4.x
  - Docker/Dockerfile examples
  - Strategy comparison table
  - Migration guide from DLX to Plugin
  - Performance tips and best practices

### Changed
- **RabbitMQQueue**: Refactored `laterRaw()` to use strategy pattern
- **Consumer**: Now calls `declareConsumerDestination()` before `basic_consume`
- **Visibility**: Made `publishBasic()` and `getRabbitMQConfig()` public for strategy access
- **Docker Environment**: Updated to PHP 8.3-cli-alpine and RabbitMQ 4.1.7-management-alpine
- **Dependencies**: Added `guzzlehttp/guzzle` to require-dev for testing
- **Tests**: Updated phpunit.xml.dist HOST to support Docker environments

### Fixed
- Consumer startup failures with "NOT_FOUND - no queue" when no jobs dispatched (PR #646)
- Flaky tests caused by early plugin detection
- Test isolation issues in functional tests

### Performance
- **98% Queue Reduction**: Plugin strategy eliminates temporary queue proliferation
- **Lower Memory**: Significantly reduced memory footprint with plugin strategy
- **Efficient Routing**: Single delayed exchange handles all delay times
- **Fast Tests**: Functional tests complete in ~0.5 seconds

### Deprecated
- None

### Removed
- None

### Security
- None

### Breaking Changes
- **None** - This release is 100% backward compatible
- DLX strategy remains the default behavior
- All existing code continues to work without changes

### Migration Notes
- No migration required for existing users
- To use plugin strategy: install plugin and update configuration
- See [README](https://github.com/websolutionfalcon/laravel-queue-rabbitmq#delayed-messages) for details

### Requirements
- PHP: ^8.0
- Laravel: ^10.0|^11.0|^12.0
- RabbitMQ: 3.6+ (Plugin strategy requires 3.8+)

### Contributors
- @salehi - Consumer auto-declaration (PR #646)
- Slava Mehovich - Strategy pattern implementation and comprehensive testing
