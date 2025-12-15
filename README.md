# ActivityGen

A PHP console application that helps you choose activities using a priority-based random selection system.

## What It Does

ActivityGen retrieves random activities from a MySQL database, weighted by their priority scores. Activities with higher priority values have a better chance of being selected. After each suggestion, you can adjust the activity's priority up or down based on your interest, creating a personalized activity recommendation system that learns from your preferences over time.

## Features

- **Priority-weighted selection**: Activities with higher priorities are more likely to be chosen
- **Interactive feedback**: Adjust activity priorities in real-time with keyboard controls
- **Continuous loop**: Keep getting suggestions until you find something you want to do
- **Docker support**: Easy setup and execution with Docker Compose

## Requirements

- Docker and Docker Compose (for containerized execution)
- MySQL database with an `t_activity` table containing `activity` and `priority` columns

## Installation

1. Clone the repository
2. Install dependencies:
   ```bash
   composer install
   ```
3. Configure database connection in `env/.db`:
   ```
   DB_HOST=your_host
   DB_DATABASE=your_database
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

## Usage

### Getting Activity Suggestions

Run the application using the provided shell script:

```bash
./bin/ag
```

Or directly with Docker Compose:

```bash
docker-compose run --rm app php bin/console
```

### Adding New Activities

Add a new activity with default priority (1.0):

```bash
./bin/ag activity:add "Your activity name"
```

Or with Docker Compose:

```bash
docker-compose run --rm app php bin/console activity:add "Your activity name"
```

### Deleting Activities

Delete an activity from the database:

```bash
./bin/ag activity:delete "Your activity name"
```

Or with Docker Compose:

```bash
docker-compose run --rm app php bin/console activity:delete "Your activity name"
```

### Controls

When an activity is displayed:
- **+** or **=** - Increase priority by 0.1
- **-** or **_** - Decrease priority by 0.1 (minimum 0.1)
- **Q** or **Enter** - Exit the application
- **Any other key** - Get next activity suggestion

## Database Schema

The application expects a MySQL table with the following structure:

```sql
CREATE TABLE t_activity (
    activity VARCHAR(255) PRIMARY KEY,
    priority DECIMAL(3,1) DEFAULT 1.0
);
```

## How It Works

1. The application calculates the maximum priority in your activity database
2. It randomly generates a "minimum roll" value between 0 and the max priority
3. Only activities with priority >= the minimum roll are eligible
4. One eligible activity is randomly selected and displayed
5. You can adjust the priority based on your interest
6. The loop continues until you exit
