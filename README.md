# Capstone Colloquium

A web-based attendance management system for academic colloquia and events. Designed for use at Gettysburg College, this application allows students to check in to academic events, professors to manage and track attendance, and administrators to oversee users, courses, and system configuration.

## Features

- **Students** – Register for and check in/out of academic events
- **Professors** – Create and manage events; view attendance by course
- **Admins** – Manage users, courses, and event data; manually override attendance records
- Tracks check-in/check-out timestamps and computes minutes present automatically
- Enforces per-course minimum attendance requirements
- Supports role-based access control (student, professor, admin)

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP |
| Database | MySQL / MariaDB |
| Frontend | *(in development)* |

## Database Schema

The application uses eight relational tables:

| Table | Description |
|-------|-------------|
| `AppUser` | All users with role-based access (student, professor, admin) |
| `Student` | Student-specific profile data |
| `Professor` | Professor-specific profile data and permitted event types |
| `Course` | Course catalog with minimum attendance requirements |
| `CourseAssignment` | Maps professors to the courses they teach |
| `EnrollmentInCourses` | Tracks which students are enrolled in which courses |
| `Event` | Colloquium/event definitions with location and time |
| `AttendsEventSessions` | Attendance records with timestamps and computed duration |

The `minutes_present` column in `AttendsEventSessions` is a generated (computed) column derived from the check-in and check-out scan times.

## Project Structure

```
Capstone-Colloquim/
├── colloquium_db.sql      # Full MySQL database schema
├── secrets/
│   └── db.php.example     # Database connection config template
└── TestFiles/             # Test data and file uploads (not committed)
```

## Setup

### Prerequisites

- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.3+
- A web server (e.g., Apache, Nginx) or PHP's built-in development server

### 1. Clone the repository

```bash
git clone https://github.com/tkhan04/Capstone-Colloquim.git
cd Capstone-Colloquim
```

### 2. Create the database

```bash
mysql -u root -p < colloquium_db.sql
```

### 3. Configure the database connection

Copy the example configuration file and edit it with your credentials:

```bash
cp secrets/db.php.example secrets/db.php
```

Edit `secrets/db.php` with your database host, port, name, user, and password. Alternatively, set the following environment variables:

| Environment Variable | Default | Description |
|----------------------|---------|-------------|
| `MYSQLHOST` / `DB_HOST` | `127.0.0.1` | Database host |
| `MYSQLPORT` / `DB_PORT` | `3306` | Database port |
| `MYSQLDATABASE` / `DB_NAME` | `colloquium` | Database name |
| `MYSQLUSER` / `DB_USER` | `root` | Database user |
| `MYSQLPASSWORD` / `DB_PASS` | *(empty)* | Database password |

> **Note:** `secrets/db.php` is listed in `.gitignore` and will never be committed to version control. Never commit real credentials.

### 4. Start the application

```bash
php -S localhost:8000
```

Then open [http://localhost:8000](http://localhost:8000) in your browser.

IF YOU WANT TO DO IT LOCALLY ^

It's also available at https://cs.gettysburg.edu/~khanta01/cs440/TestFiles/

This is publiclly available, and the URL will change to a different path/

## Contributing

1. Fork the repository and create a feature branch.
2. Make your changes and commit them with clear messages.
3. Open a pull request against the main branch.

## License

This project is a student capstone project at Gettysburg College. All rights reserved.
