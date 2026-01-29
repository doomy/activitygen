# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

ActivityGen is a PHP application that suggests activities using priority-weighted random selection. It provides both a console interface and a web interface. It's built with Symfony Console (CLI) and Slim Framework (Web API), and runs in Docker containers. The application maintains a MySQL database of activities where users can adjust priorities in real-time based on their interest level.

The application supports offline mode with automatic synchronization. When offline, it uses a local SQLite database and queues operations to sync when back online.

Both interfaces share the same backend logic through a common service layer (ActivityService), ensuring consistent behavior across CLI and web.

## Development Commands

### Running the Console Application

Primary CLI interface (uses Docker Compose):
```bash
./bin/ag
```

Direct Docker Compose command:
```bash
docker compose run --rm app php bin/console
```

### Running the Web Application

Start the web application:
```bash
./bin/web
```

This starts both the nginx and PHP-FPM containers. The web application will be available at http://localhost:8080

Stop the web application:
```bash
docker compose down
```

Rebuild web containers after code changes:
```bash
docker compose build web
./bin/web
```

### Activity Management

Add a new activity with default priority (1.0):
```bash
./bin/ag activity:add "Activity name"
```

Add a new activity with a custom starting priority (whole number):
```bash
./bin/ag activity:add "Activity name" 3
```

Delete an activity:
```bash
./bin/ag activity:delete "Activity name"
```

Get activity suggestions (default command):
```bash
./bin/ag activity:get
# or simply
./bin/ag
```

### Sync Management

Manually synchronize local and remote databases:
```bash
./bin/ag sync
```

The application automatically syncs when online:
- On startup, it syncs remote data to local database
- Pending offline operations are automatically pushed to remote
- When offline, operations are queued for later sync

### Dependency Management

Install PHP dependencies:
```bash
composer install
```

### Docker Operations

Build the container:
```bash
docker compose build
```

Run any console command:
```bash
docker compose run --rm app php bin/console <command>
```

## Architecture

### Core Components

**Symfony Console Application** (`bin/console`)
- Entry point that registers all commands
- Sets `activity:get` as the default command
- Uses ConnectionManager to determine online/offline status
- Automatically syncs data when online

**ConnectionManager** (`src/ConnectionManager.php`)
- Tests remote database connectivity on startup
- Returns RemoteDataSource when online, LocalDataSource when offline
- Manages both data sources for sync operations

**DataSource Layer** (`src/DataSource/`)
- `DataSourceInterface`: Abstract interface for data operations
- `RemoteDataSource`: MySQL implementation using PDO
- `LocalDataSource`: SQLite implementation with sync queue support

**SyncManager** (`src/Sync/SyncManager.php`)
- Handles bidirectional synchronization
- Syncs remote data to local (full copy)
- Replays queued operations to remote (delta-based)

**Service Layer** (`src/Service/`)
- `ActivityService`: Shared business logic for both CLI and web interfaces
  - Provides priority-weighted random selection
  - Handles priority adjustments with proper bounds checking
  - Manages activity CRUD operations
  - Encapsulates business rules (min priority, adjustment increment)

**Commands** (`src/Command/`)
- `GetActivityCommand`: Main interactive loop for activity selection with priority adjustments
- `AddActivityCommand`: Inserts new activities with default priority (1.0) or an optional custom whole-number priority when provided
- `DeleteActivityCommand`: Removes activities by name
- `SyncCommand`: Manual sync trigger and status display
- All commands now use ActivityService for business logic

**Web API** (`public/api/`)
- REST API built with Slim Framework
- Endpoints:
  - `GET /api/activities` - List all activities
  - `GET /api/activities/suggest` - Get random activity suggestion
  - `POST /api/activities` - Add new activity
  - `DELETE /api/activities/{name}` - Delete activity
  - `PATCH /api/activities/{name}/priority` - Adjust activity priority
  - `GET /api/sync/status` - Get online/offline status and pending operations
  - `POST /api/sync` - Manually trigger synchronization
- Uses the same ActivityService and ConnectionManager as CLI

**Web Frontend** (`public/`)
- Single-page application with vanilla JavaScript
- Two main views:
  - **Suggestions View**: Display activity suggestions with thumbs up/down buttons for priority adjustment
  - **Manage Activities View**: List, add, and delete activities
- Real-time sync status indicator with auto-polling
- Responsive design for mobile and desktop

### Selection Algorithm

The priority-weighted random selection works as follows:
1. Query maximum priority from all activities
2. Generate random "minimum roll" between 0 and max priority
3. Select from activities where priority >= minimum roll
4. Use MySQL's `ORDER BY RAND()` to pick one eligible activity

This ensures higher priority activities are selected more frequently while maintaining randomness.

### Database Schema

**Remote (MySQL) - `t_activity`:**
- `activity` (VARCHAR(255), PRIMARY KEY): Activity name
- `priority` (DECIMAL(3,1), DEFAULT 1.0): Selection weight

**Local (SQLite) - `t_activity`:**
- `activity` (TEXT, PRIMARY KEY): Activity name
- `priority` (REAL, DEFAULT 1.0): Selection weight

**Local (SQLite) - `t_sync_queue`:**
- `id` (INTEGER, PRIMARY KEY AUTOINCREMENT): Queue entry ID
- `operation` (TEXT): Operation type (ADD_ACTIVITY, DELETE_ACTIVITY, PRIORITY_ADJUST)
- `activity` (TEXT): Activity name
- `delta` (REAL): Priority change amount (for PRIORITY_ADJUST) or initial priority (for ADD_ACTIVITY)
- `timestamp` (INTEGER): Unix timestamp of operation

### Interactive Terminal Controls

The GetActivityCommand uses `stty` to capture single keypress input:
- `+` or `=`: Increase priority by 0.1
- `-` or `_`: Decrease priority by 0.1 (minimum 0.1)
- `Q` or `Enter`: Exit
- Any other key: Continue to next suggestion

## Configuration

Database credentials are stored in `env/.db` (gitignored). Use `env/.db.sample` as a template:
```
DB_HOST=<db_host>
DB_DATABASE=activitygen
DB_USERNAME=<db_user>
DB_PASSWORD=<db_password>
```

Database connections:
- Remote: Established via DatabaseConnectionFactory using `env/.db` credentials
- Local: SQLite database at `data/local.db` (automatically created)
- ConnectionManager tests connectivity and provides appropriate data source

Offline behavior:
- All operations work normally using local database
- Priority adjustments, adds, and deletes are queued
- Queue is replayed to remote when connection restored

## Code Organization

- `bin/`: Entry scripts (console PHP script, ag and web shell wrappers)
- `src/`: Application source code with PSR-4 autoloading (`App\` namespace)
- `src/Service/`: Shared business logic layer
- `src/Command/`: Symfony Console command classes
- `src/DataSource/`: Data access layer with interface and implementations
- `src/Sync/`: Synchronization management
- `public/`: Web application files
  - `index.html`: Main HTML page
  - `app.js`: Frontend JavaScript application
  - `styles.css`: CSS styles
  - `api/index.php`: REST API entry point
- `data/`: Local SQLite database (not version controlled except .gitkeep)
- `env/`: Environment configuration files (not version controlled)
- `vendor/`: Composer dependencies
- `nginx.conf`: Nginx configuration for web application
- `Dockerfile`: CLI application container
- `Dockerfile.web`: PHP-FPM container for web application
- `docker-compose.yml`: Multi-container orchestration

## Requirements

- PHP 8.1+
- PDO extension with MySQL and SQLite support
- Docker and Docker Compose
- MySQL database server (accessed via Docker networking or external host)
- SQLite for local offline storage
