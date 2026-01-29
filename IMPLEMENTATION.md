# Web Application Implementation

## Overview

This document describes the implementation of a modern web application interface for ActivityGen, complementing the existing command-line interface. Both interfaces share the same backend business logic, ensuring consistent behavior across platforms.

## Key Features Implemented

### 1. Shared Service Layer
- **Location**: `src/Service/ActivityService.php`
- **Purpose**: Centralized business logic for both CLI and web interfaces
- **Key Methods**:
  - `getRandomSuggestion()` - Priority-weighted activity selection
  - `adjustPriority()` - Modify activity priority with bounds checking
  - `getAllActivities()` - Retrieve all activities
  - `addActivity()` - Add new activity with optional priority
  - `deleteActivity()` - Remove activity by name
- **Benefits**: 
  - Single source of truth for business rules
  - Consistent behavior across interfaces
  - Easier maintenance and testing

### 2. REST API Backend
- **Location**: `public/api/index.php`
- **Framework**: Slim Framework 4.x
- **Endpoints**:
  - `GET /api/activities` - List all activities
  - `GET /api/activities/suggest` - Get weighted random suggestion
  - `POST /api/activities` - Add new activity
  - `DELETE /api/activities/{name}` - Delete activity
  - `PATCH /api/activities/{name}/priority` - Adjust priority by delta
  - `GET /api/sync/status` - Check connection status and pending operations
  - `POST /api/sync` - Manually trigger synchronization
- **Response Format**: JSON with `{success: boolean, data: object}` structure
- **Error Handling**: Proper HTTP status codes and error messages

### 3. Web Frontend
- **Technology**: Vanilla JavaScript (no framework dependencies)
- **Location**: `public/` directory
- **Files**:
  - `index.html` - Main application structure
  - `app.js` - Application logic and API interactions
  - `styles.css` - Modern, responsive styling

#### User Interface Features

**Suggestions View**:
- Clean card-based design for activity display
- Shows activity name, current priority, and minimum roll value
- Three action buttons:
  - 👎 Thumbs Down: Decrease priority by 0.1
  - 🔄 Next: Get new random suggestion
  - 👍 Thumbs Up: Increase priority by 0.1
- Visual feedback for priority changes

**Manage Activities View**:
- Sortable list of all activities (by priority, descending)
- Add new activity form with optional custom priority
- Delete button for each activity
- Real-time updates after changes

**Sync Status Indicator**:
- Online/offline status badge (green/red)
- Pending operations counter
- Manual sync button
- Auto-polling every 5 seconds

**Notifications**:
- Toast-style notifications for user actions
- Success/error states with appropriate colors
- Auto-dismiss after 3 seconds

#### Design Highlights
- Purple gradient background theme
- Responsive layout (mobile and desktop)
- Smooth transitions and hover effects
- Accessible color contrast
- Modern, clean aesthetic

### 4. Docker Infrastructure

**New Services**:
- **nginx** (nginx:alpine)
  - Serves static files (HTML, CSS, JS)
  - Proxies API requests to PHP-FPM
  - Configuration: `nginx.conf`
  
- **web** (custom PHP-FPM image)
  - Handles API requests
  - Built from `Dockerfile.web`
  - Includes Composer dependencies

**Configuration**:
- `docker-compose.yml` - Multi-service orchestration
- `nginx.conf` - Reverse proxy and routing rules
- `Dockerfile.web` - PHP-FPM container definition

**Networking**:
- Created `activitygen` bridge network
- Services communicate via service names
- Port 8080 exposed for web access

### 5. Command Refactoring

All console commands refactored to use ActivityService:
- `GetActivityCommand` - Uses shared suggestion and priority adjustment logic
- `AddActivityCommand` - Uses shared add activity logic
- `DeleteActivityCommand` - Uses shared delete logic
- Maintains backward compatibility
- Eliminates code duplication

### 6. Data Type Fixes

**Problem**: PDO was returning numeric database columns as strings, causing JavaScript type errors.

**Solution**: Added explicit type casting in DataSource classes:
- `RemoteDataSource.php` - Cast priority to float for MySQL results
- `LocalDataSource.php` - Cast priority to float for SQLite results
- Applied to all methods returning activity data

**Impact**: Ensures JSON API returns proper numeric types for JavaScript consumption.

### 7. Startup Script

**Location**: `bin/web`
**Purpose**: Simplified web application deployment
**Usage**: `./bin/web`
**Features**:
- Starts nginx and PHP-FPM services
- Displays access URL
- Shows stop command

## Technical Architecture

### Request Flow

1. **Browser** → HTTP request → **nginx** (port 8080)
2. **nginx** → Routes static files directly
3. **nginx** → Proxies `/api/*` requests → **PHP-FPM** (port 9000)
4. **PHP-FPM** → Slim Framework → **API Router**
5. **API** → **ActivityService** → **DataSource** (MySQL/SQLite)
6. **Response** flows back with JSON data

### Shared Components

```
┌─────────────────┐         ┌──────────────────┐
│   CLI Interface │         │  Web Interface   │
│  (Console Cmds) │         │  (REST API)      │
└────────┬────────┘         └────────┬─────────┘
         │                           │
         └───────────┬───────────────┘
                     │
              ┌──────▼──────┐
              │   Activity  │
              │   Service   │
              └──────┬──────┘
                     │
         ┌───────────┴───────────┐
         │                       │
    ┌────▼────┐           ┌─────▼─────┐
    │ Remote  │           │   Local   │
    │DataSource│           │DataSource │
    │ (MySQL) │           │ (SQLite)  │
    └─────────┘           └───────────┘
```

## Dependencies Added

**Composer packages** (added to `composer.json`):
- `slim/slim`: ^4.0 - Lightweight PHP framework for REST API
- `slim/psr7`: ^1.0 - PSR-7 HTTP message implementation
- `php-di/php-di`: ^7.0 - Dependency injection container

## File Structure Changes

```
activitygen/
├── bin/
│   ├── ag              (existing CLI script)
│   └── web             (NEW - web startup script)
├── public/             (NEW - web application root)
│   ├── index.html      (NEW - main HTML page)
│   ├── app.js          (NEW - JavaScript application)
│   ├── styles.css      (NEW - CSS styles)
│   └── api/
│       └── index.php   (NEW - REST API entry point)
├── src/
│   ├── Service/        (NEW - shared business logic)
│   │   └── ActivityService.php
│   ├── Command/        (UPDATED - refactored to use service)
│   ├── DataSource/     (UPDATED - added type casting)
│   └── ...
├── nginx.conf          (NEW - nginx configuration)
├── Dockerfile.web      (NEW - PHP-FPM container)
├── docker-compose.yml  (UPDATED - added web services)
├── WARP.md            (UPDATED - documented web app)
├── README.md          (UPDATED - web usage instructions)
└── IMPLEMENTATION.md  (NEW - this document)
```

## Usage

### Starting the Web Application

```bash
./bin/web
```

Access at: **http://localhost:8080**

### Stopping the Web Application

```bash
docker compose down
```

### Rebuilding After Changes

```bash
docker compose build web
./bin/web
```

## Benefits of This Implementation

1. **Unified Backend**: Both CLI and web share identical business logic
2. **Offline Support**: Works offline with automatic sync when reconnected
3. **Modern UX**: Intuitive, responsive web interface
4. **API-First**: RESTful API can support future integrations
5. **Maintainable**: Clear separation of concerns
6. **Scalable**: Docker-based deployment ready for production
7. **Consistent**: Same selection algorithm and rules across interfaces

## Known Issues & Solutions

### Issue 1: Database Permissions
**Problem**: SQLite "read-only database" error during sync
**Solution**: Ensured data directory has write permissions (chmod 777)
**Prevention**: Set proper permissions on data directory initialization

### Issue 2: Type Coercion
**Problem**: PDO returning strings instead of numbers
**Solution**: Explicit type casting in DataSource classes
**Impact**: JavaScript can now properly use numeric methods

## Future Enhancements

Potential improvements for future iterations:
- User authentication and multi-user support
- Activity categories and filtering
- Statistics and history tracking
- Mobile app (using same API)
- Activity scheduling and reminders
- Import/export functionality
- Dark mode theme toggle

## Conclusion

This implementation successfully adds a modern web interface to ActivityGen while maintaining backward compatibility with the existing CLI. The shared service layer ensures consistent behavior, and the RESTful API opens possibilities for future integrations and enhancements.
