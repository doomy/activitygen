# Offline Data Fallback Implementation Plan

## Problem Statement
ActivityGen currently requires constant connection to a remote MySQL database. When offline, the application cannot function. We need local data fallback with bidirectional synchronization.

## Current Architecture
- Commands directly use PDO to query MySQL database
- DatabaseConnectionFactory creates MySQL PDO connections
- All operations (read/write) happen immediately against remote database
- No local storage or offline capability

## Proposed Solution

### 1. Data Storage Layer Architecture

Create abstraction layer between commands and data storage:

**DataSourceInterface** (src/DataSource/DataSourceInterface.php)
- `getActivities(): array` - Fetch all activities
- `getActivityByName(string $name): ?array` - Get single activity
- `addActivity(string $name, float $priority): void`
- `deleteActivity(string $name): bool`
- `updatePriority(string $name, float $priority): void`
- `getMaxPriority(): float`
- `selectRandomActivity(float $minRoll): ?array`

**RemoteDataSource** (src/DataSource/RemoteDataSource.php)
- Implements DataSourceInterface
- Wraps current MySQL PDO operations
- Throws ConnectionException on connection failures

**LocalDataSource** (src/DataSource/LocalDataSource.php)
- Implements DataSourceInterface
- Uses SQLite database at `data/local.db`
- Same schema as MySQL: `t_activity(activity VARCHAR(255) PRIMARY KEY, priority DECIMAL(3,1))`
- Manages sync queue table: `t_sync_queue(id INTEGER PRIMARY KEY, operation VARCHAR(50), activity VARCHAR(255), delta FLOAT, timestamp INTEGER)`

### 2. Connection Management

**ConnectionManager** (src/ConnectionManager.php)
- Tests remote database connectivity
- Returns appropriate DataSource based on connection status
- Provides `isOnline(): bool` method

### 3. Sync Queue System

**SyncQueue Operations** (tracked in LocalDataSource)
Queue structure:
```sql
CREATE TABLE t_sync_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    operation VARCHAR(50) NOT NULL,
    activity VARCHAR(255) NOT NULL,
    delta FLOAT NULL,
    timestamp INTEGER NOT NULL
)
```

Operations to queue when offline:
- `PRIORITY_ADJUST`: Store activity name + delta (+0.1 or -0.1)
- `ADD_ACTIVITY`: Store activity name + initial priority
- `DELETE_ACTIVITY`: Store activity name

### 4. Synchronization Manager

**SyncManager** (src/Sync/SyncManager.php)
- `syncFromRemote(): void` - Pull remote data to local (full copy for simplicity)
- `syncToRemote(): void` - Push queued operations to remote
- `processSyncQueue(): void` - Execute queued operations against remote database

Sync strategy:
1. When coming online, first sync local data FROM remote (treat remote as source of truth)
2. Then replay queued operations TO remote
3. Clear sync queue after successful push
4. Handle conflicts by logging warnings (since we sync remote→local first, conflicts should be minimal)

### 5. Command Updates

Modify all commands to use DataSourceInterface instead of PDO directly:

**GetActivityCommand** (src/Command/GetActivityCommand.php)
- Accept DataSource instead of PDO
- Use DataSource methods for all operations
- When offline and priority adjusted, queue operation in LocalDataSource

**AddActivityCommand** (src/Command/AddActivityCommand.php)
- Use DataSource->addActivity()
- Queue operation if offline

**DeleteActivityCommand** (src/Command/DeleteActivityCommand.php)
- Use DataSource->deleteActivity()
- Queue operation if offline

**SyncCommand** (src/Command/SyncCommand.php) [NEW]
- Manual sync trigger: `./bin/ag sync`
- Shows sync status
- Forces sync if online

### 6. Application Bootstrap Changes

**bin/console updates:**
1. Try creating RemoteDataSource first
2. On connection failure, fall back to LocalDataSource
3. Display connection status to user
4. If using LocalDataSource, check if sync queue has pending operations

### 7. File Structure

```
data/
  local.db          # SQLite database (gitignored)
  .gitkeep          # Keep directory in git

src/
  DataSource/
    DataSourceInterface.php
    RemoteDataSource.php
    LocalDataSource.php
  Sync/
    SyncManager.php
  ConnectionManager.php
  DatabaseConnectionFactory.php  # Keep for RemoteDataSource

.gitignore         # Add data/local.db
```

## Implementation Steps

1. Create DataSourceInterface and implementations
2. Create LocalDataSource with SQLite schema
3. Create SyncManager with queue processing
4. Create ConnectionManager for connection detection
5. Update commands to use DataSource abstraction
6. Add SyncCommand for manual sync
7. Update bin/console bootstrap logic
8. Update .gitignore
9. Test offline→online→offline workflow

## Migration Path

For existing users:
1. First run will create local.db if it doesn't exist
2. If online, automatically sync remote→local on startup
3. No changes to remote database schema needed
4. Backward compatible - can still run with remote-only if local.db not present

## Trade-offs

**Chosen approach:**
- SQLite for local storage (vs JSON)
  - Pro: Transactional, same schema, easy queries
  - Con: Adds SQLite dependency (but PDO already required)

- Full sync from remote (vs incremental)
  - Pro: Simpler, dataset is small
  - Con: Less efficient (acceptable for small dataset)

- Queue delta operations (vs final values)
  - Pro: Handles concurrent modifications better
  - Con: Slightly more complex sync logic

- Manual sync command available
  - Pro: User control over when sync happens
  - Con: Not fully automatic (acceptable for offline-first device)
