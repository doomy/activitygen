# ActivityGen

A priority-weighted activity suggestion system with both CLI and web interfaces.

## What It Does

ActivityGen helps you choose activities using a priority-based random selection system. Activities with higher priority values have a better chance of being selected. You can adjust priorities in real-time based on your interest, creating a personalized recommendation system that learns from your preferences.

## Features

- **Dual Interface**: Use either command-line or web browser
- **Priority-weighted selection**: Activities with higher priorities are more likely to be chosen
- **Real-time adjustments**: Adjust activity priorities on-the-fly
- **Offline support**: Works offline with automatic sync when reconnected
- **Shared backend**: Both interfaces use the same business logic
- **Docker support**: Easy setup and execution with Docker Compose

## Requirements

- Docker and Docker Compose
- MySQL database (for remote sync)

## Installation

1. Clone the repository
2. Install dependencies:
   ```bash
   composer install
   ```
3. Configure database connection:
   ```bash
   cp env/.db.sample env/.db
   # Edit env/.db with your database credentials
   ```
4. Build Docker containers:
   ```bash
   docker compose build
   docker compose build web
   ```

## Usage

### Web Interface

Start the web application:
```bash
./bin/web
```

Access at: **http://localhost:8080**

The web interface provides:
- **Suggestions Tab**: Get activity suggestions with 👍/👎 buttons to adjust priorities
- **Manage Activities Tab**: View, add, and delete activities
- **Sync Status**: Real-time online/offline status indicator

Stop the web application:
```bash
docker compose down
```

### Command Line Interface

Get activity suggestions:
```bash
./bin/ag
```

Or with Docker Compose:
```bash
docker compose run --rm app php bin/console
```

### CLI Commands

Add activity:
```bash
./bin/ag activity:add "Activity name"        # Default priority (1.0)
./bin/ag activity:add "Activity name" 3       # Custom priority
```

Delete activity:
```bash
./bin/ag activity:delete "Activity name"
```

Manual sync:
```bash
./bin/ag sync
```

### CLI Controls

When an activity is displayed:
- **+** or **=** - Increase priority by 0.1
- **-** or **_** - Decrease priority by 0.1 (minimum 0.1)
- **Q** or **Enter** - Exit
- **Any other key** - Next suggestion

## Architecture

- **Backend**: PHP with shared service layer (ActivityService)
- **CLI**: Symfony Console
- **Web API**: Slim Framework (REST API)
- **Frontend**: Vanilla JavaScript single-page application
- **Database**: MySQL (remote) + SQLite (local offline cache)
- **Deployment**: Docker Compose with nginx and PHP-FPM

## Database Schema

**Remote (MySQL):**
```sql
CREATE TABLE t_activity (
    activity VARCHAR(255) PRIMARY KEY,
    priority DECIMAL(3,1) DEFAULT 1.0
);
```

**Local (SQLite):** Automatically created for offline support

## How It Works

1. The application calculates the maximum priority in your activity database
2. It randomly generates a "minimum roll" value between 0 and the max priority
3. Only activities with priority >= the minimum roll are eligible
4. One eligible activity is randomly selected and displayed
5. You can adjust the priority based on your interest
6. The loop continues until you exit
