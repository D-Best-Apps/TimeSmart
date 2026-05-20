# M365 PTO Calendar Sync — Setup

This walks through wiring TimeSmart to a shared Microsoft 365 **Group** calendar so that approved time-off requests automatically appear on it.

## Prerequisites

- Azure AD / Microsoft Entra admin access on your M365 tenant (you need to be able to create app registrations and grant admin consent).
- The shared PTO calendar lives on an existing M365 Group (an Outlook group / Teams team). If you don't already have one, create the group first in the Microsoft 365 admin center.

## 1. Register the application

1. Sign in to the [Azure Portal](https://portal.azure.com).
2. Go to **Microsoft Entra ID** → **App registrations** → **+ New registration**.
3. Name it `TimeSmart M365 Calendar Sync` (any name works).
4. **Supported account types:** *Accounts in this organizational directory only* (single tenant).
5. Leave the redirect URI blank. Click **Register**.

After creation, copy two values from the Overview page:

- **Application (client) ID** → goes into Settings as `Client ID`.
- **Directory (tenant) ID** → goes into Settings as `Tenant ID`.

## 2. Create a client secret

1. In the new app → **Certificates & secrets** → **+ New client secret**.
2. Description: `TimeSmart sync`. Expires: whatever your policy dictates (12 or 24 months is common).
3. Click **Add**. **Copy the *Value*** immediately — it is only shown once.
4. Paste it into Settings as `Client Secret`. (Stored AES-encrypted in the database.)

## 3. Grant the API permission

1. **API permissions** → **+ Add a permission** → **Microsoft Graph** → **Application permissions**.
2. Search for and add **`Group.ReadWrite.All`** (this is the permission required to create events on a Group calendar via app-only auth).
3. Back on the API permissions page, click **Grant admin consent for <your tenant>**. The status column should switch to *Granted*.

> **Why this permission and not `Calendars.ReadWrite`?** Microsoft Graph requires the broader `Group.*` permission to write to a group's calendar via the `/groups/{id}/events` endpoint. `Calendars.ReadWrite` only covers user mailboxes.

## 4. Find the Group ID

1. Go to **Microsoft Entra ID** → **Groups** → click the PTO group.
2. Copy the **Object Id** at the top of the Overview page. This is the Group ID.
3. Paste it into Settings as `PTO Calendar Group ID`.

Alternative discovery: open the group's calendar in Outlook → URL contains the group ID after `/group/{id}/calendar`.

## 5. Configure TimeSmart

In TimeSmart admin → **Settings** → scroll to **M365 PTO Calendar Sync**:

| Field | Value |
|---|---|
| Enable M365 calendar sync | checked |
| Tenant ID | from step 1 |
| Client ID | from step 1 |
| Client Secret | from step 2 |
| PTO Calendar Group ID | from step 4 |
| Calendar Time Zone | `America/Chicago` (or your tenant's tz) |

Click **Save & Test M365 Connection**. On success you'll see a green banner with the group's display name. On failure the error message tells you whether it's a token issue (bad tenant/client/secret) or a group lookup issue (bad group ID or missing consent).

## What gets synced

- **Trigger:** an admin clicks **Approve** on a time-off request in the Pending Approvals page.
- **Event subject:** `FirstName LastName — Sick` or `FirstName LastName — PTO`. *Notes are intentionally not included* in the event body — they stay private to the timesheet app.
- **All-day requests** (no time window): the event is all-day from `StartDate` through `EndDate`.
- **Single-day partial requests** (with `StartTime`/`EndTime`): the event uses the supplied time window.
- **Multi-day partial requests** (rare): collapse to all-day. Edit the event manually in Outlook if a different layout is needed.
- **Show as:** Out of Office.
- **Category:** `Sick` or `PTO` (matches the request's category).

## What does NOT sync

- **Withdraw / reject after approval:** TimeSmart does **not** delete the calendar event. The approval has already been committed; the calendar event is also already there. If you reverse the decision, delete the calendar event manually.
- **Edit after approval:** TimeSmart does not currently update or replace calendar events. If a request is edited after approval, the original event remains.

## Failure handling

If a sync attempt fails:

- The approval still goes through — the employee's request is marked `Approved` in TimeSmart regardless.
- The failure is logged to `error_log` and shown as a yellow flash banner on the Pending Approvals page.
- The `time_off_requests.M365SyncStatus` column records `error:<reason>` for that row, so you can audit failures later via SQL.
- Re-syncing a failed approval currently requires manually creating the event in Outlook (no in-app retry button — could be added later if it becomes common).

## Troubleshooting

- **"Token: invalid_client"** → bad Client ID or Client Secret. Regenerate the secret in Azure and re-paste.
- **"Token: AADSTS70011" / "invalid scope"** → the app doesn't have admin consent yet. Go back to API permissions and click *Grant admin consent*.
- **"Group lookup failed: Authorization_RequestDenied"** → the permission is set but consent wasn't granted, or it's a delegated permission instead of application.
- **"Group lookup failed: Resource '...' does not exist"** → wrong Group ID, or you're pointing at a user object instead of a group.
- **"cURL error: …"** → outbound HTTPS from the TimeSmart server to `*.microsoftonline.com` and `graph.microsoft.com` is blocked. Check firewall / egress rules.
