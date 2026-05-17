# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

ActivityGen is a PHP application that suggests activities using priority-weighted random selection. It provides both a console interface and a web interface. It's built with Symfony Console (CLI) and Slim Framework (Web API), and runs in Docker containers. The application maintains a MySQL database of activities where users can adjust priorities in real-time based on their interest level.

Activities are scoped by project, allowing users to organize activities into separate groups. The default project is "General". Both the web UI and CLI support selecting a project.

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

To avoid port conflicts when running multiple stacks, override the host port:
```bash
./bin/web 8081
# or
cp env/.web.sample env/.web
# edit env/.web and set ACTIVITYGEN_WEB_PORT
```

This starts both the nginx and PHP-FPM containers. The web application will be available at http://localhost:8080 (or your overridden port)

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

Add an activity to a specific project:
```bash
./bin/ag activity:add "Activity name" --project="Work"
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

Get suggestions from a specific project:
```bash
./bin/ag activity:get --project="Work"
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

### Database Migrations

Run migrations against the remote MySQL database:
```bash
docker compose run --rm app php bin/migrate
```

Migrations are stored in the `migrations/` directory as numbered `.sql` files (e.g. `001-add-projects.sql`) and are managed by `doomy/migrator`.

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
  - Manages project listing and lookup
  - All activity operations accept a `projectId` parameter (default `1`)
  - Encapsulates business rules (min priority, adjustment increment)

**Commands** (`src/Command/`)
- `GetActivityCommand`: Main interactive loop for activity selection with priority adjustments
- `AddActivityCommand`: Inserts new activities with default priority (1.0) or an optional custom whole-number priority when provided
- `DeleteActivityCommand`: Removes activities by name
- `SyncCommand`: Manual sync trigger and status display
- All commands use ActivityService for business logic
- `GetActivityCommand`, `AddActivityCommand`, and `DeleteActivityCommand` accept a `--project` option (default "General")

**Web API** (`public/api/`)
- REST API built with Slim Framework
- Endpoints:
  - `GET /api/projects` - List all projects
  - `GET /api/activities?projectId=X` - List all activities for a project
  - `GET /api/activities/suggest?projectId=X` - Get random activity suggestion for a project
  - `POST /api/activities` - Add new activity (accepts `projectId` in body)
  - `DELETE /api/activities/{name}?projectId=X` - Delete activity from a project
  - `PATCH /api/activities/{name}/priority` - Adjust activity priority (accepts `projectId` in body)
  - `GET /api/sync/status` - Get online/offline status and pending operations
  - `POST /api/sync` - Manually trigger synchronization
- All activity endpoints accept an optional `projectId` parameter (default `1`)
- Uses the same ActivityService and ConnectionManager as CLI

**Web Frontend** (`public/`)
- Single-page application with vanilla JavaScript
- Project selector dropdown in the header to switch between projects
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

**Remote (MySQL) - `t_project`:**
- `id` (INT, AUTO_INCREMENT, PRIMARY KEY): Project ID
- `name` (VARCHAR(180), NOT NULL, UNIQUE): Project name

**Remote (MySQL) - `t_activity`:**
- `id` (INT, AUTO_INCREMENT, PRIMARY KEY): Row ID
- `activity` (VARCHAR(180), NOT NULL): Activity name
- `priority` (DECIMAL(3,1), DEFAULT 1.0): Selection weight
- `project_id` (INT, NOT NULL, DEFAULT 1, FK → t_project.id): Owning project
- UNIQUE KEY: `(activity, project_id)`

**Local (SQLite) - `t_project`:**
- `id` (INTEGER, PRIMARY KEY AUTOINCREMENT): Project ID
- `name` (TEXT, NOT NULL, UNIQUE): Project name

**Local (SQLite) - `t_activity`:**
- `activity` (TEXT, NOT NULL): Activity name
- `priority` (REAL, DEFAULT 1.0): Selection weight
- `project_id` (INTEGER, NOT NULL, DEFAULT 1, FK → t_project.id): Owning project
- PRIMARY KEY: `(activity, project_id)`

**Local (SQLite) - `t_sync_queue`:**
- `id` (INTEGER, PRIMARY KEY AUTOINCREMENT): Queue entry ID
- `operation` (TEXT): Operation type (ADD_ACTIVITY, DELETE_ACTIVITY, PRIORITY_ADJUST)
- `activity` (TEXT): Activity name
- `delta` (REAL): Priority change amount (for PRIORITY_ADJUST) or initial priority (for ADD_ACTIVITY)
- `project_id` (INTEGER, NOT NULL, DEFAULT 1): Associated project
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

- `bin/`: Entry scripts (console PHP script, ag and web shell wrappers, migrate script)
- `src/`: Application source code with PSR-4 autoloading (`App\` namespace)
- `src/Service/`: Shared business logic layer
- `src/Command/`: Symfony Console command classes
- `src/DataSource/`: Data access layer with interface and implementations
- `src/Sync/`: Synchronization management
- `migrations/`: SQL migration files for `doomy/migrator`
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

- PHP 8.2+
- PDO extension with MySQL and SQLite support
- Docker and Docker Compose
- MySQL database server (accessed via Docker networking or external host)
- SQLite for local offline storage
