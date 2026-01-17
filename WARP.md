# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

ActivityGen is a PHP console application that suggests activities using priority-weighted random selection. It's built with Symfony Console and runs in Docker containers. The application maintains a MySQL database of activities where users can adjust priorities in real-time based on their interest level.

## Development Commands

### Running the Application

Primary interface (uses Docker Compose):
```bash
./bin/ag
```

Direct Docker Compose command:
```bash
docker compose run --rm app php bin/console
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
- Initializes database connection via DatabaseConnectionFactory

**DatabaseConnectionFactory** (`src/DatabaseConnectionFactory.php`)
- Creates PDO connections using environment variables from `env/.db`
- Configures PDO with exception mode and associative fetch mode

**Commands** (`src/Command/`)
- `GetActivityCommand`: Main interactive loop for activity selection with priority adjustments
- `AddActivityCommand`: Inserts new activities with default priority (1.0) or an optional custom whole-number priority when provided
- `DeleteActivityCommand`: Removes activities by name

### Selection Algorithm

The priority-weighted random selection works as follows:
1. Query maximum priority from all activities
2. Generate random "minimum roll" between 0 and max priority
3. Select from activities where priority >= minimum roll
4. Use MySQL's `ORDER BY RAND()` to pick one eligible activity

This ensures higher priority activities are selected more frequently while maintaining randomness.

### Database Schema

Single table `t_activity`:
- `activity` (VARCHAR(255), PRIMARY KEY): Activity name
- `priority` (DECIMAL(3,1), DEFAULT 1.0): Selection weight

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

The database connection is established on application startup and shared across all commands via dependency injection.

## Code Organization

- `bin/`: Entry scripts (console PHP script, ag shell wrapper)
- `src/`: Application source code with PSR-4 autoloading (`App\` namespace)
- `src/Command/`: Symfony Console command classes
- `env/`: Environment configuration files (not version controlled)
- `vendor/`: Composer dependencies

## Requirements

- PHP 8.1+
- PDO extension with MySQL support
- Docker and Docker Compose
- MySQL database server (accessed via Docker networking or external host)
