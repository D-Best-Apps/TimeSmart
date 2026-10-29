# Plan: Shifts, Punch Rounding, Auto-Lunch & Payroll Totals

**Goal:** make TimeSmart a drop-in replacement for Time Force at a manufacturing client:

1. Round punches to their assigned shift
2. Take lunch out automatically each day
3. Report total hours and overtime hours
4. Give a per-period total for keying into QuickBooks payroll (no integration)

Status: Phase 0 shipped. Phase 1 built (§6): shifts, rounding, auto-lunch, Pay Rules, Payroll Totals, plus the Recalculate tool and bulk shift assignment from Phase 2. Not yet built: kiosk lunch-button hiding, Rounding Audit report, office default shift, daily OT on the Pay Rules page, pay-period locking.

---

## 1. Design principles

- **Raw punches are never changed.** The kiosk and badge times stay exactly as recorded. Rounding produces *paid* times stored next to them. This keeps the audit trail intact and lets us prove the rounding is fair (see §8).
- **`TotalHours` becomes "paid hours".** Every report, export and the overtime math already read `timepunches.TotalHours` through one canonical clause (`functions/hours.php`). If the rules engine writes paid hours there, all existing reports pick it up with no per-report changes.
- **No shift means no change.** Employees with no shift assigned, and every existing install, behave exactly as they do today. The feature switches on per employee.
- **One engine, one call site pattern.** Today `calculateTotalHours()` is called from about 10 write paths (clock_action, auto_clockout, save_punches, process_edits). They'll all switch to one new helper that loads the employee's shift and policy and returns every paid column.
- **Editing a shift changes future punches only.** Past paid times are stored, so history doesn't shift when someone edits a rule. Re-applying rules to a past range is an explicit, logged admin action.

---

## 2. Concepts the client will configure

### Shift (one per named schedule: "1st Shift", "2nd Shift", "Weekend Crew")

| Setting | Example | Meaning |
|---|---|---|
| Name / color | 1st Shift, blue | Shown on users, timesheets, kiosk |
| Start / End | 7:00 AM – 3:30 PM | End before start = overnight (3rd shift 10 PM – 6:30 AM) |
| Days | Mon–Fri | Snapping only applies on scheduled days |
| **Early clock-in** | 15 min → *pay from shift start* | 6:45–7:00 is paid from 7:00 |
| **Late grace** | 5 min | 7:00–7:05 is paid from 7:00; 7:06 or later goes to the rounding increment |
| **Early clock-out grace** | 5 min | 3:25–3:30 is paid to 3:30 |
| **Stay-late window** | 10 min → *pay to shift end* | 3:30–3:40 is paid to 3:30 |
| **After the window** | *pay actual, rounded* or *cap at end unless approved* | Decides whether unapproved stay-late time becomes overtime |
| Rounding increment | 15 min, nearest | Applies to any punch outside the windows above |
| **Lunch mode** | Auto-deduct | *Punched* (today's behavior) / *Auto-deduct* / *Auto-deduct unless punched* |
| Lunch length | 30 min | Amount deducted |
| Deduct only if worked ≥ | 6 h | A 4-hour day gets no lunch taken out |

Worked example for a 7:00–3:30 shift with the settings above:

| Raw in | Raw out | Paid in | Paid out | Lunch | Paid hours |
|---|---|---|---|---|---|
| 6:48 | 3:34 | 7:00 | 3:30 | −0:30 auto | 8.00 |
| 7:04 | 3:31 | 7:00 | 3:30 | −0:30 auto | 8.00 |
| 7:12 | 3:30 | 7:15 | 3:30 | −0:30 auto | 7.75 |
| 6:50 | 5:05 | 7:00 | 5:00 | −0:30 auto | 9.50 (1.5 h counts toward weekly OT) |
| 6:55 | 11:00 | 7:00 | 11:00 | none (< 6 h) | 4.00 |

### Pay rules (company-wide)

| Setting | Default | Notes |
|---|---|---|
| Work week starts on | **Wednesday** | Today it's hardcoded Wed–Tue. The default keeps current installs unchanged. |
| Pay period | Weekly | Weekly / Bi-weekly (+ anchor date) / Semi-monthly. Sets the default report range and payroll totals grouping. |
| Weekly OT after | 40 h | |
| Daily OT after | Off | Optional (e.g. 8 h). Some states and union shops require it. |
| Default rounding (no shift) | Off | Nearest N minutes for employees without a shift |

---

## 3. Where things live in the admin portal

The current **Settings ▾** menu has General Settings, Users, Offices and Admins. Proposed:

```
Settings ▾
  ⚙️ General Settings      (unchanged: mail, M365, widgets, security)
  💵 Pay Rules             NEW: work week, pay period, overtime, default rounding
  ⏱️ Shifts                NEW: list + editor + preview + recalculate
  👥 Users                 + Shift column, shift picker, bulk "Assign shift"
  🏢 Offices               + optional "Default shift for new hires"
  🛡️ Admins
```

Why Pay Rules and Shifts get their own pages instead of sections on General Settings:

- General Settings is already long and mostly IT plumbing (SMTP, M365). Pay rules are payroll decisions and belong to whoever runs payroll.
- These pages change what people get paid, so they get their own permission, `manage_pay_rules` (super_admin by default). A `reports_only` user can see the settings but can't change them.
- The shift editor needs room for a live preview. The calculator only fits on its own page.

### ⏱️ Shifts page (`admin/shifts.php`)

- **List:** one row per shift showing name, color, time range, days, lunch rule and employee count ("1st Shift, 7:00a–3:30p, Mon–Fri, auto 30 m lunch, 42 employees"). Has Edit, Duplicate and Archive actions. A shift with employees can't be deleted, only archived.
- **Editor**, grouped into three plainly labeled boxes:
  1. *Schedule:* name, color, start, end, days
  2. *Clock-in / clock-out rounding:* the four windows plus the increment, written as sentences ("Clock-ins up to [15] minutes early are paid from the shift start")
  3. *Lunch:* mode, length, minimum hours
- **"Try a punch" preview** next to the editor: type a raw in/out and see the paid in/out, lunch and hours update live, using the same engine through a small JSON endpoint. This is the selling point in a demo.
- **Recalculate:** pick a date range and see a preview of how many punches change and the hours delta per employee. Applying it writes a `punch_changelog` entry per row (`ChangedBy` = admin, reason "Shift rules re-applied"). Locked if the range includes an already-exported pay period (phase 2).

### 💵 Pay Rules page (`admin/pay_rules.php`)

A single form: work week start (weekday dropdown), pay period type and anchor, weekly and daily OT thresholds, and default rounding for employees without a shift. A sentence at the bottom restates the result: "Your work week runs Sunday–Saturday. Overtime is paid after 40 hours per week."

### 👥 Users

- `add_user.php` / `edit_user.php`: the free-text *Scheduled start time* becomes a **Shift** dropdown (pre-filled from the office default).
- `manage_users.php`: a Shift column with the shift color chip, a filter by shift, and checkboxes with **Assign shift…** for bulk changes. A plant may have 80 people on 3 shifts.

### 🕒 Timesheets (`view_punches`, `timesheet_edit`, `timesheet_view`)

Each punch shows raw and paid together:

```
In  6:48 → 7:00     Lunch  Auto −0:30  [☐ worked through lunch]     Out 3:34 → 3:30     8.00 h
```

- A punch outside the shift windows is tagged (e.g. "Early 1:10 – not rounded") so the supervisor can see it.
- **Worked through lunch** sets a per-punch waiver that cancels the auto-deduction (`LunchWaived`), and is logged.
- Admin edits to raw times re-run the engine automatically.

### Employee portal and kiosk

- The employee timesheet shows paid times and the lunch deduction, so nobody is surprised on payday.
- Kiosk and quick-clock hide **Start/End Lunch** for employees whose shift is *Auto-deduct*, leaving only In/Out (two scans a day). *Auto-deduct unless punched* keeps the buttons.

### 📊 Reports

- **New: Payroll Totals** (the QuickBooks report). Pick a pay period and get one row per employee: Regular · Overtime · (Daily OT / Double time if enabled) · PTO · Sick · Holiday · **Total**, in decimal hours, plus a grand total row. Exports to Excel, PDF and CSV. Columns follow QuickBooks Payroll's hours-entry order so the bookkeeper can key straight across.
- **New: Rounding Audit.** Raw minutes vs paid minutes per employee for a range, net and by direction (gained/lost). This is the client's protection if anyone questions rounding (§8).
- **Existing Overtime / Summary:** remove the "Round to nearest (min)" dropdown when shifts are in use, because rounding a total that's already rounded double-rounds. Keep it only for installs with no shifts.
- Replace all hardcoded "Wednesday–Tuesday" labels with the configured week (about 10 strings across overtime, summary, missed days and tardies, plus their exports).
- **Tardies:** use the shift start (fall back to the legacy `ScheduledStartTime`).
- **Missed days:** "hours ÷ 8" becomes "hours ÷ shift length" when a shift is set.

---

## 4. Data model

New migration `deploy/database/migrations/2026_11_xx_shifts_and_pay_rules.sql` (and the same tables in `timeclock-schema.sql`):

```sql
CREATE TABLE shifts (
  ID                 int AUTO_INCREMENT PRIMARY KEY,
  Name               varchar(100) NOT NULL,
  Color              varchar(7)  NOT NULL DEFAULT '#0a4a6b',
  StartTime          time NOT NULL,
  EndTime            time NOT NULL,                 -- < StartTime => overnight
  Days               set('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri',
  EarlyInMinutes     smallint NOT NULL DEFAULT 0,   -- early clock-in window -> paid from start
  LateInGraceMinutes smallint NOT NULL DEFAULT 0,
  EarlyOutGraceMinutes smallint NOT NULL DEFAULT 0,
  LateOutMinutes     smallint NOT NULL DEFAULT 0,   -- stay-late window -> paid to end
  AfterWindowPolicy  enum('actual','cap') NOT NULL DEFAULT 'actual',
  RoundIncrement     tinyint NOT NULL DEFAULT 0,    -- 0 = exact
  RoundMode          enum('nearest','up','down') NOT NULL DEFAULT 'nearest',
  LunchMode          enum('punched','auto','auto_unless_punched') NOT NULL DEFAULT 'punched',
  LunchMinutes       smallint NOT NULL DEFAULT 30,
  LunchMinWorkedHours decimal(4,2) NOT NULL DEFAULT 6.00,
  Archived           tinyint(1) NOT NULL DEFAULT 0
);

ALTER TABLE users      ADD COLUMN ShiftID int NULL, ADD COLUMN IF NOT EXISTS ScheduledStartTime time NULL;
ALTER TABLE Offices    ADD COLUMN DefaultShiftID int NULL;
ALTER TABLE timepunches
  ADD COLUMN ShiftID        int NULL,          -- shift applied when computed
  ADD COLUMN PaidIn         time NULL,
  ADD COLUMN PaidOut        time NULL,
  ADD COLUMN LunchDeductMin smallint NULL,     -- minutes actually taken out (punched or auto)
  ADD COLUMN LunchWaived    tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN RawHours       decimal(5,2) NULL; -- unrounded, for audit/display
UPDATE timepunches SET RawHours = TotalHours WHERE RawHours IS NULL;

INSERT IGNORE INTO settings (SettingKey, SettingValue) VALUES
  ('PayWeekStartDay','3'), ('PayPeriodType','weekly'), ('PayPeriodAnchor',NULL),
  ('OvertimeWeeklyHours','40'), ('OvertimeDailyHours','0'),
  ('DefaultRoundIncrement','0'), ('DefaultRoundMode','nearest');
```

> ⚠️ **Existing bug this fixes:** `users.ScheduledStartTime` is used by `add_user.php` and the tardies reports, but it isn't in `timeclock-schema.sql` or any migration. On a **fresh install** (like this client's), *Add User* fails with "Unknown column". The `ADD COLUMN IF NOT EXISTS` above fixes it, but it's worth shipping as a standalone fix first.

---

## 5. Engine

New `app/functions/pay_rules.php`, with pure functions in the style of `hours.php`:

- `payPolicy(mysqli $conn): array`: reads the settings above, cached per request.
- `applyShiftRules(array $punch, ?array $shift, array $policy): array` (**pure**, no DB). Takes raw `TimeIN/LunchStart/LunchEnd/TimeOut` and returns `PaidIn, PaidOut, LunchDeductMin, RawHours, TotalHours, Flags[]`.
  - Snap rules apply only when the punch date is one of the shift's `Days` and the raw in is within ~3 h of the shift start. Otherwise the punch gets only the increment rounding and a flag (Saturday overtime, called in early).
  - Overnight shifts resolve the end against the next day, the same way `calculateTotalHours()` rolls over today.
  - Lunch: *punched* → actual lunch; *auto* → `LunchMinutes` if worked ≥ threshold and not waived; *auto_unless_punched* → actual if punched, else auto.
- `computePunchColumns(mysqli $conn, int $empID, string $date, ...raw times, bool $lunchWaived): array`: loads the shift and policy, calls `applyShiftRules`, and returns the column map. **Every writer that sets `TotalHours` today calls this instead:**
  - `functions/clock_action.php` (5 UPDATEs)
  - `scripts/auto_clockout.php`, `scripts/auto_clockout_scheduled.php`
  - `admin/save_punches.php`, `admin/process_edits.php` (2)
- `calculateTotalHours()` stays as the raw-span primitive that `applyShiftRules` uses internally.
- `hours.php`: `payPeriodStart()` reads `PayWeekStartDay` (default Wed). `weeklyOvertime()` takes thresholds from the policy and adds optional daily OT. The JS in `admin_timesheet_edit.js` that hardcodes `> 40` gets the threshold from the page.

**Tests:** the project has no test harness today. `applyShiftRules` is pure, so add a small CLI test script (`deploy/scripts/test_pay_rules.php`) that runs a table of raw→paid cases like the worked example in §2, including overnight, missed lunch-end, unscheduled day and waived lunch. Run it before each release.

---

## 6. Phasing

**Phase 0: standalone fix (now).** `ScheduledStartTime` migration so Add User works on fresh installs.

**Phase 1: sellable MVP for this client**
1. Migration + schema (§4)
2. `pay_rules.php` engine + CLI tests; wire all `TotalHours` writers
3. Pay Rules page (week start, OT) + configurable `payPeriodStart` + label cleanup
4. Shifts page (list, editor, live preview)
5. Shift picker on add/edit user + column on Users list
6. Raw → paid display and "worked through lunch" on admin timesheets; paid times on employee timesheet
7. Payroll Totals report + Excel/CSV export
8. Remove report-level rounding when shifts exist; tardies use shift start

**Phase 2: polish and protection**
- Kiosk hides lunch buttons for auto-lunch shifts
- Rounding Audit report
- Recalculate tool with preview; bulk shift assign; office default shift
- Daily OT; lock exported pay periods

**Phase 3: later / upsell**
- **Shift differential:** hours by shift on Payroll Totals (2nd/3rd shift premium pay items in QuickBooks). Manufacturing clients often ask for this next.
- Rotating schedules / per-date overrides
- Departments and supervisor-scoped approvals

---

## 7. Things to retire or leave alone

- `scripts/auto_lunch.php` and `scripts/auto_clockout_scheduled.php` are hardcoded to one named owner on one install. Auto-deduct lunch replaces the first in a general way. Neither should be scheduled on the client install. Keep them as they are for the existing site.
- The legacy `ScheduledStartTime` stays as a fallback for tardies until every user has a shift. A later migration can turn each distinct start time into a shift.

---

## 8. Compliance note (worth telling the client)

US federal rules (FLSA, 29 CFR 785.48) allow rounding to the nearest 5, 10 or 15 minutes, and to a scheduled start, **only if it doesn't, over time, consistently cut employees' pay**. Paying early arrivals from the shift start is generally fine *if they aren't working* in that window. Policies that only round in the company's favor have drawn lawsuits. We give them:

- Raw punches kept forever and shown next to paid times
- The **Rounding Audit** report showing the net effect per employee
- Configurable windows, so their payroll person or attorney sets the policy, not us

---

## 9. Questions to confirm with the client before building

1. Shift list: names, start and end times, days. Any overnight shift?
2. How early can people clock in, and is that time paid?
3. Grace minutes on late arrival and early leave. What rounding increment applies outside the grace window (5, 6, 15 min)?
4. Is staying past shift end paid automatically, or only when a supervisor approves it?
5. Lunch: fixed length? Taken out even if nobody punches? Minimum hours before it's deducted? Do workers ever work through lunch?
6. Work week start day, and pay frequency (weekly, bi-weekly)?
7. Overtime: weekly over 40 only, or daily as well?
8. Do 2nd/3rd shift get a pay differential?
9. Which QuickBooks (Online Payroll vs Desktop)? Get a screenshot of their hours-entry screen so the export column order matches.
