# Demo / Local Test Environment (Windows)

Run TimeSmart on a Windows PC with demo data — for trying features, demos, and testing the
kiosk on an iPad over Wi-Fi. Everything is portable: PHP, MariaDB and Composer are unzipped
into `.devenv\` inside the repo (git-ignored). Nothing is installed on the PC.

## Start

```powershell
powershell -ExecutionPolicy Bypass -File deploy\dev\setup.ps1   # first time: downloads ~150 MB, builds the demo DB
powershell -ExecutionPolicy Bypass -File deploy\dev\start.ps1   # every time: prints the URLs
```

- On this PC: <http://localhost:8080/>
- On an iPad on the same Wi-Fi: `http://<this PC's IP>:8080/kiosk/` (start.ps1 prints the exact address).
  The first time, Windows asks whether `php.exe` may use the network — allow **Private networks**.
- Stop: Ctrl+C in the start window, or `deploy\dev\stop.ps1` (also stops MariaDB).
- Fresh demo data (dates move with today): `deploy\dev\setup.ps1 -Reseed`

## Remove

```powershell
powershell -ExecutionPolicy Bypass -File deploy\dev\uninstall.ps1
```

Stops everything and deletes `.devenv\` (PHP, MariaDB, the demo database, downloads) and
`app\vendor\` (Composer packages). Nothing else on the PC was changed.

## Demo logins

Sign in at <http://localhost:8080/user/login.php> with the full name and the password
**`Demo123!`** (every demo account).

| Name | Role | Shift | Badge | PIN |
|---|---|---|---|---|
| Pat Admin | Super admin | Office | 900001 | 9001 |
| Robin Reports | Reports only | Office | 900002 | 9002 |
| Alex Rivera | Employee | 1st Shift | 100001 | 1001 |
| Jamie Chen | Employee | 1st Shift | 100002 | 1002 |
| Morgan Lee | Employee | 1st Shift | 100003 | 1003 |
| Taylor Brooks | Employee | 1st Shift | 100004 | 1004 |
| Casey Nguyen | Employee | 1st Shift | 100005 | 1005 |
| Jordan Patel | Employee | 1st Shift | 100006 | 1006 |
| Riley Johnson | Employee | 2nd Shift | 100007 | 1007 |
| Avery Kim | Employee | 2nd Shift | 100008 | 1008 |
| Quinn Martinez | Employee | 2nd Shift | 100009 | 1009 |
| Sam Ortiz | Employee | none | 100010 | 1010 |

On the kiosk, type a PIN on the keypad and tap ✓, or type a badge number the same way
(a USB/Bluetooth badge reader "types" it the same way).

## What's in the demo

- **Shifts:**
  - 1st Shift 7:00a–3:30p: rounds to 15 min, lunch taken out automatically (one scan in, one out).
  - 2nd Shift 3:30p–midnight: punched lunch, so the kiosk asks *Start Lunch / Clock Out*
    mid-shift; time past the shift isn't paid unless approved.
  - Office 8:00a–4:30p: no rounding, lunch taken out unless punched.
- **About three weeks of punches** ending today, and today's shift still on the clock:
  - Overtime: Alex and Jordan stay late most days, and Jordan works Saturday mornings.
  - Late arrivals: Taylor and Riley are often late (Tardies report).
  - Absences: Sam has an unexcused no-show the week before last.
- **Waiting on Pending Approvals:**
  - four timesheet change requests (a wrong clock-in, missed clock-outs, late lunch);
  - Riley's missing lunch-end punch;
  - three PTO requests.
- **Already decided:** approved, denied (with the reason) and withdrawn time off,
  including a partial day. Also approved and rejected change requests, which employees see
  on their dashboard.
- **History:** about ten admin corrections, each with its reason and the paid hours before and
  after, plus employee notes:
  - a time fix;
  - a missed punch added;
  - a duplicate deleted;
  - "worked through lunch";
  - overtime approved past the shift.
- **Payroll:** the week before last is closed, so those days are read-only. Last week is open for
  review. Use Payroll Totals to show the QuickBooks numbers and to close it.
- **Work week Monday–Sunday**, overtime after 40 hours.
- Date-range reports such as Tardies default to *this month*. Early in a month, pick a range
  that covers the last few weeks.

The data is made by `deploy/scripts/seed_demo.php`, which refuses to run on a database that
already has users.
