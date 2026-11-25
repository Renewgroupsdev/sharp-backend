# SHARP Programme API

Laravel 13 + PostgreSQL + Sanctum port of the legacy PHP `backend/api`.
Response envelope is unchanged: `{"ok": true, ...}` / `{"ok": false, "error": "..."}`.

## Setup

```bash
createdb sharp_app                       # or create it in pgAdmin
# edit .env: DB_USERNAME / DB_PASSWORD
php artisan migrate --seed               # demo roster, password "sharp2026"
php artisan serve
```

## Endpoints (all under `/api`, `Authorization: Bearer <token>`)

| Legacy | New | Roles |
|---|---|---|
| `auth/login.php` | `POST auth/login` | public (throttled) |
| `auth/logout.php` | `POST auth/logout` | any |
| `auth/change_password.php` | `POST auth/change-password` | any |
| `me.php` | `GET me` | any |
| `colleges.php` GET | `GET colleges` | any |
| `colleges.php?suggest_code=` | `GET colleges/suggest-code?name=` | any |
| `colleges.php` POST | `POST colleges` | super_admin, admin, ob |
| `users.php` GET | `GET users`, `GET users/{public_id}` | any |
| `users.php` POST | `POST users` | policy: `UserPolicy@create` |
| `user_action.php` | `POST users/{public_id}/suspend\|activate\|reset-password` | policy: `UserPolicy@manage` |
| `attendance.php` GET | `GET attendance?student_id=` | policy: `UserPolicy@viewAttendance` |
| `attendance.php` POST | `POST attendance` | super_admin, admin, ob, mentor |
| `commonfloor.php` | `GET/POST common-floor` | any |
| `commonfloor_moderate.php` | `POST common-floor/{id}/flag\|unflag\|mute` | super_admin, admin, ob |
| `audit.php` | `GET audit-logs` | super_admin, admin, ob |
| `dashboard.php` | `GET dashboard` | super_admin, admin, ob |

Validation failures return HTTP 422 (legacy returned 400).

## Structure

- `app/Enums` — Role, UserStatus, AttendanceStatus, CollegeStatus, ModerationAction
- `app/Services` — business logic (User, College, Attendance, CommonFloor, Dashboard, PublicIdGenerator, AuditLogger)
- `app/Policies/UserPolicy` — permission matrix
- `app/Http/Requests` — validation; `app/Http/Resources` — response shapes
- `app/Http/Controllers/Api` — thin controllers
- `bootstrap/app.php` — middleware aliases and JSON error envelope

## PostgreSQL specifics

- `pg_trgm` extension (migration creates it; needs a superuser on first run)
- `ILIKE` search, `COUNT(*) FILTER`, advisory lock for public-ID generation
- Token lifetime: `SHARP_TOKEN_TTL_DAYS`; expired tokens pruned daily (`php artisan schedule:work`)
