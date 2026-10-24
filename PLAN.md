# PLAN — local_rvc_enrolment_management

Design and build tracker for **RVC Enrolment Management**. This Moodle local plugin
lets site administrators suspend, one course at a time, `database` and
`databaseextendedv2` enrolments for people who are no longer listed in that
method's feed. It can re-activate those suspensions, and later delete them.

Design agreed with Brian on 2026-10-22. Nothing is built yet.

## Names

| What | Value |
|---|---|
| Component | `local_rvc_enrolment_management` |
| Moodle folder | `local/rvc_enrolment_management` |
| Display name | RVC Enrolment Management |
| Repository | `RVC-Learn-Digital-Team/moodle-rvc_enrolment_management`, submodule of `sofia_api_explorer` |
| Baseline | Moodle 4.5 LTS and later; MySQL 8.4 in production |

## Operating constraints (decided)

- **Site administrators only.** Every page and action calls `require_admin()`. There are
  no custom capabilities. A read-only view for other staff would be a separate plugin.
- **Menus:** Site administration > Plugins > Local plugins > RVC Enrolment Management has
  only two entries: **Settings** and **Manage enrolments** (renamed from Suspensions on 2026-10-23). There are no links in courses or
  anywhere else.
- **Both methods are in scope:** core `database` and CoSector `databaseextendedv2`.
- **Any enrolled person** can be suspended, whatever their role.
- **One course at a time.** The tool never runs feed queries across courses.
- **Settings are never copied.** The tool reads each enrol plugin's own settings every
  time it runs, so changes are picked up automatically.
- **It reuses each enrol plugin's own connection code.** The feed is read through the enrol
  plugin's protected `db_init()` and `db_get_sql()` methods, called via PHP reflection, so
  ODBC (core) and CoSector's own connection handling stay theirs to maintain. If a plugin
  lacks either method, that method is treated as unavailable.
- **Credentials are never shown or logged.** Feed access runs inside an output buffer
  that is discarded, in case an enrol plugin's `debugdb` is switched on. Driver error
  text is never displayed.

### What the learn settings tell us (captured 2026-10-22)

| | `database` | `databaseextendedv2` |
|---|---|---|
| Transport | `odbc_mssql`, database `Moodle_Snapshot` | `mysqli`, no database name in settings |
| Feed table | `COURSE_ENROLMENT` | `new_moodle_enrolment` |
| Course match | `COURSE_ID` = course `idnumber` | `course_id` = course `idnumber` |
| Person match | `USER_EMAIL` = Moodle `username` | `user_id` = Moodle `username` |
| When someone leaves the feed | Keep (`unenrolaction=1`) | Suspend (`unenrolaction=2`) |
| Sync | Hourly on weekdays | Nightly at about 01:00, run by the hosting company's own cron |

These values are for orientation only; the code reads them at run time. learn-uat is a
clone. Brian will point its core plugin at `Moodle_Snapshot_UAT`.

`Moodle_Snapshot` holds the current academic year only, so a course missing from the
feed is normal for past years.

## Rules

### Who can be suspended

A person can be suspended in a course when all of these hold:

1. They have an **active** enrolment (`user_enrolments.status = 0`) by `database` or
   `databaseextendedv2` in that course.
2. That method's feed was read in this request without error.
3. The feed doesn't list them for this course. The course key comes from the plugin's
   `localcoursefield` (idnumber) and the person key from its `localuserfield` (username).
   Matching ignores capitals and leading or trailing spaces, so in doubt the person
   counts as still listed.

Neither the course nor the person needs to be in the feed. **Only being listed
stops a suspension** ("Still in the feed. Remove it there first").

- If the course idnumber is empty, no feed query runs: no feed can list anyone for that
  course, so everyone is shown as not listed, with a banner explaining why.
- If the idnumber has stray spaces, the feed is queried with the value as stored and,
  if that finds nothing, again trimmed.

### Feed unavailable

If the connection or the query fails, suspensions for that method are refused. The page
shows: "Enrolment management for {method} is suspended until access to {transport ·
database name} is restored." The database name appears only when the plugin's settings
contain one. Host, user and password never appear.

### Suspend

- Done through `enrol_plugin::update_user_enrol(..., ENROL_USER_SUSPENDED)`. This changes
  status only: roles and groups are kept.
- At confirmation, the tool reads the feed again. It suspends only people still not listed
  and still active, and reports any skipped with the reason.
- **Batch warning:** if the selection is over the threshold percentage of the course's
  active enrolments for that method, the confirm page asks for an "I understand" tick.
  It's a warning only and can be overruled.
- An optional reason is stored with each suspension.

### Re-activate (undo)

- Available only for suspensions this tool made that are still suspended and not deleted.
  It doesn't re-check the feed.
- Core Moodle offers no status change for database enrolments in the Participants page
  (`allow_manage()` is false), so this is the only easy undo.
- For `databaseextendedv2`, a re-activated person who is still absent from its feed is
  suspended again by that plugin's next nightly sync (`unenrolaction=2`).

### Delete

- Manual only: an admin ticks and confirms. There is no scheduled task. An optional
  reason is stored with each deletion.
- Applies to enrolments this tool suspended and, since 2026-10-24, to database-family
  enrolments suspended by anything else (for example the Extended plugin's own sync).
- Allowed only when all of these hold:
  - the waiting period has passed (default 3 days). For this tool's suspensions it runs
    from the recorded suspension. For other suspensions there is no suspension date, so
    it runs from the enrolment's last change (`user_enrolments.timemodified`); a last
    change in the future, or older than the waiting period, does not hold deletion back;
  - the enrolment is still suspended;
  - the feed can be read and still doesn't list the person.
- Done through `enrol_plugin::unenrol_user()`. The confirm page states the effect: this
  enrolment and the roles and groups it created are removed. If it's the person's last
  enrolment in the course, Moodle also removes all their roles, group memberships, grades
  and last-access record there.
- Enrolments suspended by anything else can be deleted, but not re-activated, by this
  tool. (Until 2026-10-24 they were shown for information only.)

### Record

There is one table, `local_rvc_enrolment_management_suspension`, with one row per
suspension made by this tool, and one per deletion of an enrolment suspended elsewhere
(no suspending administrator, and the enrolment's last change as the suspension date):

- course, user, user enrolment ID, enrol instance ID, method;
- when suspended and by whom, and the optional reason;
- whether the course was in the feed at that moment;
- when re-activated and by whom; when deleted, by whom and the optional reason
  (`deletereason`, added in version 2026102400 by the plugin's first upgrade step).

A Privacy API provider (metadata, export, delete, userlist) covers it.

## Screens

### Settings (admin settings page)

- Days before deletion is allowed (default 3).
- Batch warning threshold, as a percentage (default 50).

### Manage enrolments: find a course

**Filters** (a Moodle form submitted by GET, so the web address keeps them and the
browser's Back button works). Every filled-in filter must match:

- **Course ID:** one or more numbers; a course matches if its ID is any of them.
- **Course name:** each word must appear in the full name or the short name.
- **ID number:** each word must appear in it (for example `1vet 2026`).
- **Category:** a drop-down showing the category hierarchy. Choosing a category includes
  its subcategories.
- **Method:** Database, Extended or either.
- Words are split on spaces and matched without regard to capitals, using
  `$DB->sql_like()` with escaped parameters.
- **Search** and **Reset** buttons. Reset clears every filter.

**Results:**

- Only courses that have a `database` or `databaseextendedv2` instance.
- A sortable `table_sql` with 50 rows per page: ID, ID number, short name, full name,
  category path, visibility, and active/suspended counts for each method.
- Opening a row goes to the course page.
- No feed queries run from this screen.

### Manage enrolments: course page

1. **Course details:** ID, ID number, short name, full name, category path, visibility
   and an "Open course" link (new tab).
2. **One feed card per method present:** method plus transport, with the database name
   only when the settings contain one. It shows whether the connection worked, whether
   the course is in the current feed (for information only), and when that sync last ran.
3. **Not in the feed: can be suspended.** A table inside a form, with a tick column and
   select-all, as on the Participants page. Nothing is ticked at the start.
   - Columns: name and username, account (**suspended** or **deleted** user), method,
     roles in the course, last access to the course, other active enrolments in the
     course.
   - Filters by method and role. "Select all" means every row matching the filters.
   - No pages, because it's one course.
4. **Still in the feed: can't be suspended.** No tick boxes; the reason is shown.
5. **Suspended by this tool:** date, by whom, reason, and days elapsed. Re-activate
   and, once the delay has passed, delete.
6. **Already suspended by something else:** its own tick list, with the last change,
   "Can be deleted from", and a Delete selected button. No re-activate.

**Confirm suspension** (Moodle form):

- Lists the selected people and counts per method.
- Asks for the batch-warning tick if needed, and has an optional reason box.
- Confirm or Cancel, then a results page listing who was suspended and who was skipped.

Confirm re-activation and confirm deletion follow the same pattern. Deletion spells out
its effect.

## Testing

- PHPUnit runs in a Moodle checkout. Core `enrol_database` is pointed at a feed table
  created in the test database, as core's own tests do, so the feed reader runs through
  real `db_init()` / `db_get_sql()`.
- `databaseextendedv2` is closed source and can't run in CI. Its route is proved on
  learn-uat.
- The tests cover: listed versus not listed (capitals and spaces); empty and untrimmed
  idnumbers; feed unavailable; a person re-listed between list and confirm; the batch
  warning; re-activation; the deletion delay and its re-checks; enrolments suspended
  elsewhere; and privacy export and delete.
- CI uses the `moodle-plugin-ci` workflow from `moodle-local_curricmap`: PHP lint, PHPCS
  (Moodle standard), PHPDoc, XMLDB and PHPUnit on MySQL 8.4 and PostgreSQL.
- There is no PHP or Moodle on the Mac. Unpushed code is checked with a `/tmp` harness
  on the playground (see AGENTS.md). The full checks are CI, then the playground rerun
  after Brian installs the plugin, then learn-uat.

## Milestones

- [x] **M1 Scaffold:** `version.php`, language strings, settings page, the two admin
      entries, install.xml with the suspension table, privacy provider, CI. Tested on
      the Docker playground Moodle 4.5.15 on 2026-10-22, then uninstalled from it. CI has
      not run yet, because nothing is pushed.
- [x] **M2 Feed reader and eligibility:** the reflection-based reader, matching rules and
      feed-unavailable handling, with PHPUnit. 18 tests pass on PostgreSQL 16 in the
      playground; MySQL 8.4 waits for CI.
- [x] **M3 Find a course:** filters, category hierarchy, reset, results table. Built
      2026-10-23 (v0.2.0) with 8 PHPUnit tests; not yet seen in a browser. Course rows
      link to `course.php`, which is a placeholder until M4.
- [x] **M4 Course page and suspend:** sections 1–6, confirm, re-check, record. Built
      2026-10-23 (v0.3.0) with 7 more PHPUnit tests (33 in all). The course page's
      tool-suspension list has no actions yet; re-activate and delete come in M5.
- [x] **M5 Re-activate and delete:** both confirm flows and the deletion delay. Built
      2026-10-23 (v0.4.0) with 5 more PHPUnit tests.
- [x] **M5a Delete enrolments suspended elsewhere,** with an optional reason for every
      deletion (Brian, 2026-10-24). Built as v0.5.0 (version 2026102400).
- [ ] **M6 learn-uat validation** against `Moodle_Snapshot_UAT` and the extended feed.
      Checklist below.
- [ ] **Playground rerun:** once Brian has installed the plugin through
      `configs/moodle.yaml`, rerun the full PHPUnit suite on the playground
      (initialising its PHPUnit environment if needed).

## M6 learn-uat validation checklist

No code is expected for M6. Stop at the first failure and report which step it was.

**Before testing (Brian and the hosting partner)**

1. learn-uat's core `enrol_database` points at `Moodle_Snapshot_UAT`, and its sync runs.
2. The plugin is deployed to learn-uat at version 2026102400 or later, and the upgrade
   has run.
3. Agree a test course and one or two test people whose enrolments can be suspended and
   deleted. Set "Days before deletion" to 0 for the test, then back to 3 afterwards.
4. Note that learn-uat's MIS was reading the live feed views, not the `_UAT` ones, when
   last checked. If that is still so, the Extended feed on learn-uat is live data.

**Read-only checks**

1. Site administration → Plugins → Local plugins → RVC Enrolment Management shows
   Settings (3 and 50 by default) and Manage enrolments.
2. The search finds a known current-year course by ID number words, and by category.
3. **A core Database course:**
   - The card reads "Database · ODBC (SQL Server) · Moodle_Snapshot_UAT", "Feed connected"
     and "This course is in the current feed (N rows)".
   - N matches the number of `COURSE_ENROLMENT` rows for that course's ID number in
     `Moodle_Snapshot_UAT`.
4. **A Veterinary Nursing (Extended) course:** the card reads "Extended · MySQL", then either:
   - "Feed connected": the extended plugin's own connection code worked; or
   - "Enrolment management for Extended is suspended until access to MySQL is restored":
     the CoSector plugin doesn't offer the same connection functions as core. Report it;
     the fix needs its code.
5. A previous-year course shows "not in the current feed", with everyone under "Not in
   the feed".
6. **Spot checks** for one course:
   - Three people under "Not in the feed" have no feed row for that course.
   - Three under "Still in the feed" do have one.

**Changes, on the agreed test people only**

1. **Suspend** one person with a reason. The results page says 1 suspended, and the course
   page lists them under "Suspended by this tool" with the reason.
2. **Re-activate** them, and check that the enrolment is active again. For an Extended
   person, the plugin's next nightly sync may suspend them again.
3. **Suspend and delete:**
   - Suspend them again, then delete them.
   - The confirm page shows the consequences, and flags "last enrolment in this course"
     where it applies.
   - Afterwards, the course's Participants page no longer lists that enrolment.
4. **Delete an enrolment suspended elsewhere:**
   - In an Extended course, choose a person under "Already suspended by something else"
     who the SRS tool suspended and who is not in its feed.
   - "Can be deleted from" reads "Now" unless their enrolment changed in the last 3 days.
   - Delete them with a reason. Afterwards, the course's Participants page no longer
     lists that enrolment.
5. **Batch warning:**
   - In a small course, tick more than half the people under "Not in the feed" and
     choose Suspend selected.
   - The warning appears, and Confirm needs the "I understand" tick. Cancel without
     suspending.

**Report back:** which steps passed, a screenshot of each feed card, and any error text.

## Open

- Should a completely empty feed table also count as "feed unavailable"? An
  institution-wide feed with no rows at all is the only signal that can tell a broken
  feed from a legitimate absence. Not included unless Brian agrees.
