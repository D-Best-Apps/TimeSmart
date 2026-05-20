# M365 PTO Calendar Sync — Setup

This walks through wiring TimeSmart to a shared Microsoft 365 **Group** calendar so that approved time-off requests automatically appear on it.

## Prerequisites

- Azure AD / Microsoft Entra admin access on your M365 tenant (you need to be able to create app registrations and grant admin consent).
- **Exchange Online admin** access (you'll run a few PowerShell commands to grant the app access to the group's mailbox).
- The shared PTO calendar lives on an existing M365 Group (an Outlook group / Teams team). If you don't already have one, create the group first in the Microsoft 365 admin center.

> **Heads up:** modern M365 tenants enforce a second layer of authorization on top of Microsoft Graph called **Application Access Policy** (Exchange Online RBAC for applications). Even after you grant `Group.ReadWrite.All` Graph permission, calendar reads/writes will be denied with `ErrorAccessDenied` until you also create an Application Access Policy in Exchange Online (step 4 below). This is the most-missed step.

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

## 3. Grant the Microsoft Graph permission

1. **API permissions** → **+ Add a permission** → **Microsoft Graph** → **Application permissions**.
2. Search for and add **`Group.ReadWrite.All`** (this is the permission required to create events on a Group calendar via app-only auth).
3. Back on the API permissions page, click **Grant admin consent for <your tenant>**. The status column should switch to *Granted*.

> **Why this permission and not `Calendars.ReadWrite`?** Microsoft Graph requires the broader `Group.*` permission to write to a group's calendar via the `/groups/{id}/events` endpoint. `Calendars.ReadWrite` only covers user mailboxes.

## 4. Grant Exchange Online mailbox access (Application Access Policy)

Even with the Graph permission granted above, Exchange Online will return `403 ErrorAccessDenied` until you tell it which mailboxes this app is allowed to touch. Run these in PowerShell as a tenant admin.

```powershell
# 4.1 Install + connect (one-time)
Install-Module ExchangeOnlineManagement -Scope CurrentUser   # if not already installed
Connect-ExchangeOnline

# 4.2 Create a mail-enabled security group listing the mailboxes the app may access.
# Use the M365 Group's primary SMTP (e.g. ptocalendar@dbest.com).
New-DistributionGroup `
  -Name "TimeSmart-AllowedMailboxes" `
  -PrimarySmtpAddress "timesmart-allowed@yourdomain.com" `
  -Type Security `
  -Members "ptocalendar@yourdomain.com"

# 4.3 Create a RestrictAccess policy scoping the TimeSmart app to that group.
# Replace -AppId with the Application (client) ID from step 1.
New-ApplicationAccessPolicy `
  -AppId 00000000-0000-0000-0000-000000000000 `
  -PolicyScopeGroupId "timesmart-allowed@yourdomain.com" `
  -AccessRight RestrictAccess `
  -Description "TimeSmart M365 calendar sync — PTO Calendar only"

# 4.4 Verify. Should return AccessCheckResult: Granted.
Test-ApplicationAccessPolicy `
  -Identity ptocalendar@yourdomain.com `
  -AppId 00000000-0000-0000-0000-000000000000
```

**`RestrictAccess`** means the app is restricted to **only** the listed mailboxes — narrower (and safer) than the tenant-wide reach implied by `Group.ReadWrite.All`. If you ever need to add more shared calendars, just add their SMTPs to the distribution group from 4.2.

> **Propagation:** Microsoft documents up to 1 hour for the policy to take effect across all Exchange Online front-end servers. In practice it's usually 5–15 minutes, but `Test-ApplicationAccessPolicy` returning `Granted` can precede the runtime evaluation catching up. If a test sync still 403s right after running this, wait 10 minutes and try again.

## 5. Find the Group ID

1. Go to **Microsoft Entra ID** → **Groups** → click the PTO group.
2. Copy the **Object Id** at the top of the Overview page. This is the Group ID.
3. Paste it into Settings as `PTO Calendar Group ID`.

Alternative discovery: open the group's calendar in Outlook → URL contains the group ID after `/group/{id}/calendar`.

## 6. Configure TimeSmart

In TimeSmart admin → **Settings** → scroll to **M365 PTO Calendar Sync**:

| Field | Value |
|---|---|
| Enable M365 calendar sync | checked |
| Tenant ID | from step 1 |
| Client ID | from step 1 |
| Client Secret | from step 2 |
| PTO Calendar Group ID | from step 5 |
| Calendar Time Zone | `America/Chicago` (or your tenant's tz) |

Click **Save & Test M365 Connection**. On success you'll see a green banner with the group's display name. On failure the error message tells you whether it's a token issue (bad tenant/client/secret), a Graph permission issue (step 3 not done), or an Exchange Online policy issue (step 4 not done or not propagated yet).

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

| Error message | Likely cause | Fix |
|---|---|---|
| `Token: invalid_client` | Bad Client ID or Client Secret | Regenerate the secret in Azure (step 2) and re-paste |
| `Token: AADSTS70011` / "invalid scope" | App doesn't have admin consent | Re-do step 3 — *Grant admin consent for tenant* must be clicked |
| `Group lookup failed: Authorization_RequestDenied` | Wrong permission type (Delegated instead of Application), or consent not granted | Step 3 — make sure you added the **Application** permission, not Delegated |
| `Group lookup failed: Resource '...' does not exist` | Wrong Group ID | Step 5 — re-copy the **Object Id** of the group |
| **`Create event: Access is denied. Check credentials and try again.`** (or `ErrorAccessDenied` on any `/groups/{id}/calendar*` call) | Exchange Online Application Access Policy not configured or not yet propagated | **Step 4.** Verify with `Test-ApplicationAccessPolicy` — must return `AccessCheckResult: Granted`. If it already returns `Granted`, wait 10–30 minutes for runtime propagation |
| `cURL error: …` | Outbound HTTPS from the TimeSmart server blocked | Firewall / egress rules — allow `*.microsoftonline.com` and `graph.microsoft.com` |
