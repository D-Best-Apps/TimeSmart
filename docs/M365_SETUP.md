# M365 PTO Calendar Sync — Setup

Wires TimeSmart to a Microsoft 365 calendar so approved time-off requests automatically appear on it. **Use a shared mailbox** for the calendar — it's the reliably-working path. A Unified Group calendar fallback exists in the code, but is brittle (see Appendix at the bottom).

## Prerequisites

- **Microsoft Entra (Azure AD) admin** to create an app registration and grant Graph admin consent.
- **Exchange Online admin** to create a shared mailbox, register the app in Exchange, and assign an RBAC role.
- The PTO calendar lives on a **shared mailbox** (create one if you don't have it; see step 0).

> **Reach summary:** for app-only access to an Exchange Online calendar, three layers must all line up:
> 1. **Microsoft Graph permission** (`Calendars.ReadWrite` Application) — granted with admin consent.
> 2. **Exchange Online Application Access Policy** — restricts the app to specific mailboxes.
> 3. **Exchange Online RBAC for Applications role assignment** — required in modern tenants.
>
> Skipping any of these returns `ErrorAccessDenied` from Graph with identical messages, making the layers hard to tell apart.

## 0. Create the shared mailbox (if needed)

Exchange admin center → **Recipients → Mailboxes → Add a shared mailbox**.

- Name: `PTO Calendar`
- Email address: `ptocalendar@<yourdomain>`
- Grant Full Access to whoever needs to manage the calendar in Outlook.

After it's created, end users add it to Outlook (Calendar → Add → From Address Book → search for the mailbox).

## 1. Register the application

1. Azure Portal → **Microsoft Entra ID → App registrations → + New registration**.
2. Name: `TimeSmart M365 Calendar Sync`. Single tenant. No redirect URI. Register.
3. Copy from the Overview page:
   - **Application (client) ID** → TimeSmart Settings `Client ID`
   - **Directory (tenant) ID** → TimeSmart Settings `Tenant ID`

## 2. Create a client secret

App → **Certificates & secrets → + New client secret** → copy the **Value** (only shown once) → TimeSmart Settings `Client Secret`. Stored AES-encrypted in the DB.

## 3. Grant the Microsoft Graph permission

App → **API permissions → + Add a permission → Microsoft Graph → Application permissions**.

- Add **`Calendars.ReadWrite`** (sufficient for shared mailbox calendars).
- Click **Grant admin consent for &lt;tenant&gt;** — status must turn green.

> If you previously added `Group.ReadWrite.All` for the Unified Group attempt, it's harmless to leave it but no longer required.

## 4. Application Access Policy (restricts which mailboxes the app may touch)

```powershell
Install-Module ExchangeOnlineManagement -Scope CurrentUser   # one-time
Connect-ExchangeOnline

# 4.1 Distribution group listing the allowed mailbox(es)
New-DistributionGroup `
  -Name "TimeSmart-AllowedMailboxes" `
  -PrimarySmtpAddress "timesmart-allowed@yourdomain.com" `
  -Type Security `
  -Members "ptocalendar@yourdomain.com"

# 4.2 Restrict the app to the mailboxes in that group
New-ApplicationAccessPolicy `
  -AppId 00000000-0000-0000-0000-000000000000 `   # ← App ID from step 1
  -PolicyScopeGroupId "timesmart-allowed@yourdomain.com" `
  -AccessRight RestrictAccess `
  -Description "TimeSmart M365 calendar sync — PTO Calendar only"

# 4.3 Verify — should return AccessCheckResult: Granted
Test-ApplicationAccessPolicy `
  -Identity ptocalendar@yourdomain.com `
  -AppId 00000000-0000-0000-0000-000000000000
```

## 5. RBAC for Applications role assignment

Modern Exchange Online tenants require the runtime evaluator to find an RBAC role assignment for the app, on top of the Application Access Policy.

> **CRITICAL — App ID vs Service Principal Object ID:**
> `New-ServicePrincipal -ServiceId` wants the **Service Principal's Object ID**, NOT the App ID. They look similar (both GUIDs) but they're different objects. Using the App ID for `-ServiceId` errors with `AADServicePrincipalNotFound`. Find the SP Object ID in Azure Portal → **Microsoft Entra ID → Enterprise applications** → click the app → Properties → **Object ID**. Or via PowerShell:
>
> ```powershell
> Connect-MgGraph -Scopes "Application.Read.All"
> Get-MgServicePrincipal -Filter "AppId eq '00000000-0000-0000-0000-000000000000'" |
>   Select-Object Id, AppId, DisplayName
> ```

```powershell
Connect-ExchangeOnline

# 5.1 Register the app in Exchange
New-ServicePrincipal `
  -AppId 00000000-0000-0000-0000-000000000000 `       # ← App ID
  -ServiceId aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee `   # ← Service Principal Object ID (DIFFERENT)
  -DisplayName "TimeSmart M365 Calendar Sync"

# 5.2 Scope the role to the PTO mailbox
New-ManagementScope `
  -Name "TimeSmart-PTO-Scope" `
  -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'ptocalendar@yourdomain.com'"

# 5.3 Assign the role
New-ManagementRoleAssignment `
  -App 00000000-0000-0000-0000-000000000000 `         # ← App ID
  -Role "Application Calendars.ReadWrite" `
  -CustomResourceScope "TimeSmart-PTO-Scope"

# 5.4 Verify
Get-ManagementRoleAssignment -RoleAssignee 00000000-0000-0000-0000-000000000000
```

> **Propagation:** 5–30 minutes for both the Application Access Policy and the RBAC role assignment to take effect. The `Test-*` and `Get-*` cmdlets can return success before the runtime catches up.

## 6. Configure TimeSmart

Admin → **Settings → M365 PTO Calendar Sync**:

| Field | Value |
|---|---|
| Enable M365 calendar sync | ✓ |
| Tenant ID | step 1 |
| Client ID | step 1 |
| Client Secret | step 2 |
| **PTO Calendar Mailbox (UPN)** | the shared mailbox's primary SMTP (e.g. `ptocalendar@dbest.com`) |
| Calendar Time Zone | `America/Chicago` (or your tz) |

Click **Save & Test M365 Connection**. Success → green banner with the calendar name. Failure → see Troubleshooting.

## What gets synced

- **Trigger:** admin clicks **Approve** on a time-off request.
- **Event subject:** `FirstName LastName — Sick` or `… — PTO`. *Notes are deliberately omitted from the event body* — they stay private to TimeSmart.
- **All-day requests:** event spans `StartDate` through `EndDate` as all-day.
- **Single-day partial requests** (with `StartTime` / `EndTime`): event uses the supplied window.
- **Multi-day partial requests** (rare): collapse to all-day.
- **Show as:** Out of Office.
- **Category:** `Sick` or `PTO`.

## What does NOT sync

- **Withdraw / reject after approval:** the calendar event is **not** deleted automatically. Reverse manually if needed.
- **Edit after approval:** TimeSmart does not currently update existing events.

## Failure handling

- The approval is committed even if sync fails — the employee's request is `Approved` regardless.
- Failures: logged to `error_log`, shown as a yellow banner on Pending Approvals, recorded in `time_off_requests.M365SyncStatus`.
- No in-app retry yet; recreate the event in Outlook manually if a sync failed.

## Troubleshooting

| Error | Likely cause | Fix |
|---|---|---|
| `Token: invalid_client` | Bad Client ID / Secret | Regenerate secret (step 2), re-paste |
| `Token: AADSTS70011` | App lacks admin consent | Step 3 — click *Grant admin consent* |
| `Mailbox calendar lookup failed: ResourceNotFound` | Wrong mailbox UPN in TimeSmart Settings | Step 6 — confirm with `Get-Mailbox -Identity <upn> \| Format-List PrimarySmtpAddress` |
| `Create event: Access is denied` *(ErrorAccessDenied)* | Step 4 or step 5 missing/not propagated | Verify both: `Test-ApplicationAccessPolicy` returns `Granted`; `Get-ManagementRoleAssignment -RoleAssignee <AppId>` shows the role. If both look right, wait 10–30 min for propagation |
| `AADServicePrincipalNotFound` from `New-ServicePrincipal` | Used App ID where SP Object ID was expected | See critical note in step 5 |
| `cURL error: …` | Outbound HTTPS blocked | Allow `*.microsoftonline.com` and `graph.microsoft.com` egress |

## Appendix — Unified Group calendar (legacy / not recommended)

If you really want to target a Microsoft 365 Group's calendar instead of a shared mailbox, the code still supports it: leave the **Mailbox UPN** setting empty and populate **PTO Calendar Group ID** with the group's Object ID. *(The Group ID field has been removed from the Settings UI; set the `m365_group_id` row directly in the `settings` table if you need it.)* Additional Azure work needed: change the Graph permission from `Calendars.ReadWrite` to `Group.ReadWrite.All`. Be aware that Exchange Online frequently refuses app-only writes to Unified Group calendars even with everything correctly configured (member additions of Service Principals are explicitly blocked by Graph; owner addition often silently fails). If you go this route and hit `ErrorAccessDenied` that nothing fixes, switch to a shared mailbox — that's what this doc recommends.
