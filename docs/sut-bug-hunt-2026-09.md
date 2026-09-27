# SUT Bug Hunt, 2026-09

Open Ultiorganizer bugs found by the harness on 2026-09-26/27, with severity, cause, the test
that pins each one and the fix direction. Everything here is reproducible: every entry has a
test on harness branch `sut-bug-hunt` that fails against the SUT and passes against a fix
sketch.

## Baseline

- **SUT:** `../ultiorganizer`, branch `fix/api-auth-header-and-rate-limit` @ `9a944bf`.
- **Harness:** branch `sut-bug-hunt`, one bug per commit (listed per entry), stacked on
  `shared-persistent-cache` (see [H1](#h1-http-tests-could-not-flush-apaches-query-cache)).
  Nothing has been pushed.
- **Red set against the SUT** (`./test:matrix`): Smoke 69 failures in all 8 cases,
  Integration 7 (baseline-default and config-overrides), Export 3 and Api 4 (baseline-default
  only). No other failures.
- **Fix sketches:** `../uo-fix-scope` (API), `../uo-fix-private` (pages and lib),
  `../uo-fix-all` (both), combined diff `../uo-fix-all.patch`. The whole matrix is green on
  `../uo-fix-all`. The sketches are minimal proofs, not reviewed patches. The part for
  [B19](#b19-reflected-xss-on-32-login-gated-pageparameter-pairs) is only a request-level shim.

## How to verify a fix

1. Apply the fix in the SUT (or a worktree of it).
2. Run the entry's test: `./test:smoke --sut-path <sut> --test-filter <TestClass>` (use
   `test:api`, `test:export` or `test:integration` for tests in those suites).
3. When all fixes in an area are in, run `./test:matrix --sut-path <sut>`. It must be fully
   green; the only expected failures are pins whose bug is not fixed yet.
4. Cherry-pick the pin commits for the fixed bugs onto `main`.

Things to know when writing HTTP-level tests like these: flush the `db_query_*` caches right
before each request, and never toggle `PersistentCacheEnabled` (its own `uo_setting` read is
cached, so the toggle lands only after the TTL).

## Severity scale

| Severity | Meaning |
|---|---|
| **High** | Exploitable security flaw reachable by anonymous visitors, any logged-in account, or a link sent to an admin. |
| **Medium** | Security flaw needing a scoped role (team or event admin), or wrong data/leak of data an event chose to hide. |
| **Low** | Robustness, cosmetic, or narrow edge case. |

## Summary

| ID | Sev | Who can trigger | Issue |
|---|---|---|---|
| B01 | High | anonymous | Private-event gate bypassed by adding `&season=<public event>` |
| B02 | High | anonymous | Private-event gate checks only the first id in `pools=` |
| B03 | Medium | anonymous | `allplayers` lists players of private events |
| B04 | Medium | anonymous | All-time scoreboard includes private events |
| B05 | Medium | anonymous | Team card history lists private events and hidden spirit averages |
| B06 | Low | anonymous | Event maintenance bypassed by adding `&season=<other event>` |
| B07 | Medium | API token holder | `/api/v1/gameplay` returns spirit scores the event hides |
| B08 | Medium | anonymous | Spirit-standings statistics rank teams for events that hide spirit |
| B09 | Medium | anonymous | Team CSV exports spirit points the event hides |
| B10 | Low | anonymous / token | Games in hidden pools shown by `/api/v1/games` and the team card |
| B11 | Low | API token holder | `/api/v1/gameplay` returns synthesized times when an event hides times |
| B12 | High | any logged-in account | `admin/saveteampools.php` rewrites any pool's standings without a rights check |
| B13 | Medium | event admin | Six pages authorize one event but write another event's rows |
| B14 | Medium | team admin | `GameAddPlayer()` rosters and renumbers players from other teams/events |
| B15 | Low | note author / link publisher | Read-only events still let authors edit notes and publishers remove media links |
| B16 | High | anonymous (link) | Reflected XSS on `defensestatus` via `pools=` |
| B17 | High | event admin → all visitors | Stored XSS: names printed raw on 11 public pages |
| B18 | High | registered user / team admin → admins | Stored XSS on 6 login-gated pages (incl. inline `<script>`) |
| B19 | High | anyone who gets an admin to open a link | Reflected XSS on 32 login-gated page/parameter pairs |
| B20 | Medium | data correctness | Draws and forfeits miscounted in division and archived statistics |
| B21 | Medium | data correctness | Team CSV: zero results when spirit is hidden; forfeit wins not counted |
| B22 | Low | API token holder | Season-scoped token gets 403 on `/api/v1/games` without an event |
| B23 | Low | anonymous | `seriesstatus` prints PHP warnings for an unknown `sort` |
| B24 | Low | superadmin | `add_field_accounts` plugin aborts on a field name with an apostrophe |
| H1 | — | harness | HTTP tests could not flush Apache's query cache (fixed on branch) |

Suggested fix order: B12, B16, B19, B17, B18 (auth and XSS), then B01–B05, B13, B14, then
the rest.

---

## Access control and private events

### B01 Private-event gate bypassed by adding `&season=<public event>`

- **Severity:** High. Anonymous visitors can read pages of private events.
- **Where:** `lib/season.functions.php` — `EnforcePrivateEventAccessForView()` via
  `MaintenanceSeasonFromView()`.
- **What happens:** `?view=teamcard&team=<private team>&season=HRN2026` renders the private
  team card; the same for `playercard&player=<private player>`. Without the `season`
  parameter the request is correctly redirected.
- **Cause:** `MaintenanceSeasonFromView()` returns the event of the *first* id parameter it
  finds, checking `season` before `series`, `pool`, `game`, `team`, `player`... The gate
  checks only that one event, while the page uses a different parameter.
- **Test:** `tests/Smoke/PrivateEventAccessTest.php` —
  `testNamingAPublicEventDoesNotOpenAPrivateTeamCard`,
  `testNamingAPublicEventDoesNotOpenAPrivatePlayerCard` (commit `78d0c34`).
- **Fix direction:** resolve the event for *every* id parameter present and refuse if any is
  inaccessible. The sketch adds `RequestSeasonsFromView($rawView)` and loops over it in both
  gates. Careful: `iget()` reads `filter_input(INPUT_GET)`, not `$_GET`, so you cannot
  resolve parameters one by one by swapping `$_GET`; query each parameter's event directly.

### B02 Private-event gate checks only the first id in `pools=`

- **Severity:** High (same root as B01).
- **Where:** `MaintenanceSeasonFromView()`, `pools` branch (`reset($poolIds)`).
- **What happens:** `?view=games&pools=200,<private pool>` lists the private pool's games.
- **Test:** `PrivateEventAccessTest::testListingAPublicPoolFirstDoesNotOpenAPrivatePoolSchedule`
  (commit `34a61f3`).
- **Fix direction:** check the event of every pool id in the list (also affects
  `scorestatus`, `defensestatus`, `ical`, which read `pools`).

### B03 `allplayers` lists players of private events

- **Severity:** Medium.
- **Where:** `allplayers.php` / `PlayerListAll()` in `lib/player.functions.php`.
- **What happens:** `?view=allplayers&list=all` names players whose only roster is in a
  private event. The `public_event` feature (#67) filtered the other list pages
  (`allteams`, `clubcard`, `countrycard`, `playercard`) with `CanAccessSeason()`; this one
  was missed.
- **Test:** `PrivateEventAccessTest::testAllPlayersListLeavesOutPlayersOfAPrivateEvent`
  (commit `bc5b15a`).
- **Fix direction:** filter rows by `CanAccessSeason(<player's team's event>)`, ideally in
  SQL (join `uo_team`/`uo_series`, restrict to accessible events).

### B04 All-time scoreboard includes private events

- **Severity:** Medium.
- **Where:** `ScoreboardAllTime()` in `lib/statistical.functions.php`, used by
  `statistics.php?list=playerscoresall` (top 100, Callahan top 20, per-type tables).
- **What happens:** private events' `uo_player_stats` are summed in; the "Latest event /
  team" column names the private team. `clubcard.php` filters its team list first, so it
  is not affected.
- **Test:** `PrivateEventAccessTest::testAllTimeScoreboardLeavesOutPrivateEventStatistics`
  (flips the same row public/private) (commit `47e6e0b`).
- **Fix direction:** restrict `ps.season` to accessible events before aggregating (the
  sketch builds an `IN (...)` list via `CanAccessSeason()`).

### B05 Team card history lists private events and hidden spirit averages

- **Severity:** Medium.
- **Where:** `teamcard.php` history tables — `TeamStatisticsByName()` and
  `TeamSpiritAveragesByName()`.
- **What happens:** the history of every same-named team is shown, including private
  events (name and link); archived spirit averages are shown for events whose
  `showspiritpoints` is off. `playercard.php` filters its history with `CanAccessSeason()`.
- **Test:** `tests/Smoke/TeamCardHistoryTest.php` (both methods) (commit `08d7123`).
- **Fix direction:** filter `$seasons` by `CanAccessSeason($row['season'])`; skip spirit
  rows unless `ShowSpiritScoresForSeason($row['season'])`. The related
  `TeamSpiritCategoryHistoryAveragesByName()` average has the same exposure.

### B06 Event maintenance bypassed by adding `&season=<other event>`

- **Severity:** Low.
- **Where:** `EnforceSoftMaintenanceForView()` — same first-parameter lookup as B01.
- **What happens:** with HRN2026 in maintenance, `teamcard&team=300` gives 503 but
  `teamcard&team=300&season=<other public event>` renders.
- **Test:** `tests/Smoke/EventMaintenanceBypassTest.php` (commit `0dd2dc0`).
- **Fix direction:** same helper as B01; refuse if any named event is in maintenance.

### B12 `admin/saveteampools.php` rewrites any pool's standings without a rights check

- **Severity:** High. Any logged-in account (self-registration is enabled in some profiles).
- **Where:** `admin/saveteampools.php` (the pool editor's AJAX save).
- **What happens:** the page needs only a login. Team moves go through the rights-checked
  `PoolDeleteTeam()`/`PoolAddTeam()`, but afterwards `ResolvePoolStandings()` runs for every
  pool id in the body, unchecked. A body of `200` skips the checked calls, so a role-less
  account rewrites pool 200's standings; for `mvgames=1` pools the resolver also makes
  automatic moves and sets the next pool visible. Works on read-only events too.
- **Test:** `tests/Smoke/SaveTeamPoolsRightsTest.php` (commit `3852cae`).
- **Fix direction:** resolve only pools where `hasEditTeamsRight($poolInfo['series'])`.
  Better: give `ResolvePoolStandings()` callers an explicit rights check, since the
  function itself is unguarded.

### B13 Six pages authorize one event but write another event's rows

- **Severity:** Medium (needs an event admin role somewhere; affects other events).
- **Pattern:** the page or lib function checks rights on the event/division named in the
  URL or a parameter, then writes an id taken from the request without checking it belongs
  to that event. Correctly scoped references to copy: `CanChangeSeasonUserRole()`,
  `SaveFinalStandingsOrder()`, `SpiritDeleteSotgToken()`, `Remove*ProfileUrl()`,
  `SetGame()`'s `pool` check.

| Case | Where | Test (commit) | Fix direction |
|---|---|---|---|
| Recalculate another event's pool | `admin/seasonstandings.php` POST `recalculate` → `ResolvePoolStandings($_POST['PoolId'])` | `SeasonStandingsCrossEventTest` (`2679936`) | require `hasEditTeamsRight(<pool's series>)` |
| Delete another event's reservation | `admin/reservations.php` → `RemoveReservation($id, $season)` deletes by id only | `ReservationRemovalCrossEventTest` (`5c43762`) | `DELETE ... WHERE id=%d AND season='%s'` |
| Rename another event's scheduling names | `admin/seasonmoves.php` (no page check) → `PoolSetSchedulingName($id, $name, $season)` | `SchedulingNameCrossEventTest` (`c991ea3`) | check the name is used by the event's games/moves |
| Seed another event's team | `admin/seriesseeding.php` → `SetTeamSeeding($seriesId, $teamId, $seed)` | `SeriesSeedingCrossEventTest` (`57baa16`) | `UPDATE ... WHERE team_id=%d AND series=%d` |
| Schedule a game into another event's reservation | `admin/saveschedule.php` → `ScheduleGame($game, $time, $reservation)` | `ScheduleReservationCrossEventTest` (`4515558`) | reservation's `season` must equal the game's event (it also hands that reservation's `resgameadmin`s rights over the game) |
| Put another event's team in a game | `admin/editgame.php` → `SetGame()` stores posted `hometeam`/`visitorteam` | `GameFixtureCrossEventTest` (`33db794`) | reject teams whose `TeamSeason()` differs from `GameSeason()` |

All tests are in `tests/Smoke/`. Note for the `SetGame()` fix: harness commit `74eb60b`
already moved `ScoresheethistoryFunctionsLibTest`'s temporary reassignment team into
division 100, since it used a team with no division.

### B14 `GameAddPlayer()` rosters and renumbers players from other teams/events

- **Severity:** Medium (team admin of either team of a game).
- **Where:** `GameAddPlayer()` in `lib/game.functions.php`; reached from
  `user/`, `mobile/`, `scorekeeper/addplayerlists.php`, which pass every posted player id.
- **What happens:** any player id is added to the game's roster (their "games played" and
  stats inflate), and the posted jersey number is written onto that player's own
  `uo_player.num`, even for a player of another event.
- **Test:** `tests/Integration/GameRosterForeignPlayerTest.php` (commit `13d5bbc`).
- **Fix direction:** refuse unless the player's team is the game's home or visitor team.
  (`GameAddNewPlayer()` has the same unchecked `$teamId` but has no callers.)

### B15 Read-only events still let authors edit notes and publishers remove media links

- **Severity:** Low.
- **Where:** `CanManageGameComment()` in `lib/comment.functions.php` (author branch);
  `CanRemoveMediaUrl()` in `lib/url.functions.php` (publisher branch).
- **What happens:** `hasEditGameEventsRight()` is revoked by `event_readonly`, but these
  alternative branches never check the flag, so a note's author can still edit or delete
  it, and a link's publisher can still remove a game media link (and
  `RemoveMediaUrl()` deletes the game's media events). Adding a link is already blocked.
  `CanManageSpiritComment()` falls through to the same author branch.
- **Tests:** `tests/Integration/GameCommentReadonlyTest.php` (`4916461`),
  `tests/Integration/GameMediaReadonlyTest.php` (`388c045`). The media wrapper `die()`s, so
  the test pins the predicate.
- **Fix direction:** check `isEventReadonly($season) && !canBypassEventReadonly($season)` in
  both branches (for media only when `owner='game'`; player/club links are not
  event-bound).

## Hidden data (spirit, pools, times)

### B07 `/api/v1/gameplay` returns spirit scores the event hides

- **Severity:** Medium.
- **Where:** `api_handle_gameplay()` in `api/v1/router.php`.
- **What happens:** per-category spirit points are returned whenever the event has a spirit
  mode and the game is finished, without `CanViewSpiritScoresForGame()` (which
  `gameplay.php` uses and #92 added to the spirit CSV). The fixture event has
  `showspiritpoints=0`.
- **Test:** `tests/Api/ApiSpiritVisibilityTest.php` (commit `1fe2754`).
- **Fix direction:** add `&& CanViewSpiritScoresForGame($gameId, $seasonInfo)` to the spirit
  block's condition.

### B08 Spirit-standings statistics rank teams for events that hide spirit

- **Severity:** Medium.
- **Where:** `statistics.php?list=spiritstandings` → `SeasonSpiritTopTeamsBySeriesType()`.
- **What happens:** archived spirit averages are ranked for every accessible event, ignoring
  `showspiritpoints`. `teams.php?list=byspirit` gates the same view with
  `ShowSpiritScoresForSeason()`.
- **Test:** `tests/Smoke/StatisticsSpiritStandingsTest.php` (commit `93e8479`).
- **Fix direction:** `continue` for seasons where `!ShowSpiritScoresForSeason($season['season_id'])`.

### B09 Team CSV exports spirit points the event hides

- **Severity:** Medium.
- **Where:** `TeamsToCsv()` in `lib/team.functions.php` (`ext/teamscsv.php`).
- **What happens:** the `SpiritPoints` column sums spirit for games with `show_spirit=1`,
  ignoring the event flag. `ext/spiritcsv.php` refuses to export anything unless
  `ShowSpiritScoresForSeason()`.
- **Test:** `tests/Export/TeamsCsvSpiritTest.php` (commit `1faedff`).
- **Fix direction:** zero the spirit sum unless `ShowSpiritScoresForSeason($season)`. Fix
  together with B21.

### B10 Games in hidden pools shown by `/api/v1/games` and the team card

- **Severity:** Low.
- **Where:** `api_handle_games()` (`TimetableGames()`/`TimetableGrouping()` calls) and
  `teamcard.php` (`TimetableGames($teamId, "team", "all", "time")`).
- **What happens:** both omit `$onlypublic`, so games in pools whose playoff root is not
  visible are listed. `games.php` and `ical.php` pass it (#84); `games&team=300` hides what
  `teamcard&team=300` shows.
- **Tests:** `tests/Api/ApiPoolVisibilityTest.php` (commit `2e5a4fb`),
  `tests/Smoke/HiddenPoolTeamCardTest.php` (commit `517af29`).
- **Fix direction:** pass `true` as `$onlypublic`. Decide whether the same applies to the
  unpinned siblings listed under [Not pinned](#not-pinned).

### B11 `/api/v1/gameplay` returns synthesized times when an event hides times

- **Severity:** Low.
- **Where:** `api_handle_gameplay()`.
- **What happens:** with `hide_time_on_scoresheet` on, the score-sheet flows synthesize point
  times (`docs/scoresheet.md` in the SUT) and every gameplay view hides them and the
  time-on-offence stats; the API returns goal `time` and `time_on_offence` as if recorded.
- **Test:** `tests/Api/ApiHiddenTimesTest.php` (commit `c66938b`).
- **Fix direction:** null goal times and `time_on_offence`/`time_on_offence_per_goal` when the
  flag is on (event times and `halftime` likely too).

## Cross-site scripting

All XSS pins append a harmless element (`<x-harness-...>`) and assert it never appears
unescaped in a `text/html` response. CSV, JSON, iCal and RSS responses were not counted.

### B16 Reflected XSS on `defensestatus` via `pools=`

- **Severity:** High (anonymous link).
- **Where:** `defensestatus.php` — `$poolIds = explode(",", iget("pools"))` is written raw
  into the sort links' `href`.
- **Test:** `tests/Smoke/ReflectedParameterEscapingTest.php::testDefenseStatusDropsMarkupInPools`
  (`scorestatus` is the passing contrast) (commit `0420724`).
- **Fix direction:** reduce to positive integers like `scorestatus.php` does.

### B17 Stored XSS: names printed raw on 11 public pages

- **Severity:** High. An event admin (or resadmin for reservation groups) injects markup
  seen by every visitor, including superadmins.
- **Test:** `tests/Smoke/StoredNameEscapingTest.php` (data provider, one case per page)
  (commits `425ae3b`, `004a309`).

| Page | Raw value | Location |
|---|---|---|
| `teamcard` | event name heading, division name in `<h1>` | `teamcard.php` lines ~28, ~199 |
| `clubcard`, `playercard`, `countrycard` | current event name heading | `U_(CurrentSeasonName())` without `utf8entities` |
| `poolstatus&series=` | event/division name in `<title>` | `poolstatus.php` `$title .= U_(...)` |
| `seriesstatus`, `spiritstatus` | division name in `<title>` | `$title .= U_($seriesinfo['name'])` |
| `games`, `played`, `timetables` | reservation group in an HTML comment (`-->` breaks out) and in the grouping links (2+ groups) | `lib/timetable.functions.php` `<!-- res:`; `games.php` ~271/273 |
| `ext/export.php` | event name | `SeasonName($season)` |
| `ext/index.php` | grouping name in the `<object data=...>` URL | `$seltournament` |

- **Fix direction:** wrap in `utf8entities()`. Root cause: `pageTopHeadOpen()` in
  `menufunctions.php` prints `$title` raw, so every caller must escape it. Consider
  escaping there instead and auditing callers that pass pre-escaped titles.

### B18 Stored XSS on 6 login-gated pages

- **Severity:** High. Injected by a registered user (own display name) or team admin (club
  name), executed in admin sessions.
- **Test:** `tests/Smoke/StoredNameEscapingLoggedInTest.php` (commit `91eb775`).

| Page | Raw value | Location |
|---|---|---|
| `user/enrollteam`, `admin/addseasonteams` | club names concatenated into a `"..."` string inside an inline `<script>` (a quote or `</script>` breaks out) | `$orgarray .= "\"" . $row['name'] . "\","` |
| `user/addmedialink` | the user's own display name and each link's publisher | `$userinfo['name']`, `$url['publisher']` |
| `admin/seasonadmin` | organizer and category | `U_($info['organizer'])` |
| `admin/stats` | team names in the drag list, division heading | `$team['teamname']`, `U_($team['seriesname'])` |
| `user/respgames` | reservation groups in grouping links | `U_($groupLabel)` |

- **Fix direction:** `utf8entities()` for HTML; `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`
  for the script array.

### B19 Reflected XSS on 32 login-gated page/parameter pairs

- **Severity:** High (a crafted link opened by a logged-in admin runs script in that session).
- **Where:** raw `$_GET`/`iget()` values copied into form actions, links or `<title>`:

| Page | Parameters |
|---|---|
| `admin/accreditation` | season |
| `admin/addlocations` | season |
| `admin/addreservation` | reservation, season |
| `admin/addseasonlinks` | season |
| `admin/addseasons` | season |
| `admin/addseasonseries` | season |
| `admin/addseasonusers` | season |
| `admin/editgame` | game (in `<title>`), season |
| `admin/editstanding` | pool, season, team |
| `admin/locations` | season |
| `admin/poolgames` | pool, season |
| `admin/poolmoves` | season |
| `admin/seasongames` | group, pool |
| `admin/seasonmoves` | order, season, series |
| `admin/seasonseries` | season |
| `admin/seriesgames` | season |
| `admin/serieteams` | season |
| `admin/stats` | season |
| `user/addextraemail` | user |
| `user/respgames` | group, hidden, season, series |
| `user/teamplayers` | game |

- **Test:** `tests/Smoke/ReflectedParameterEscapingLoggedInTest.php` (data provider;
  `admin/seasonadmin` is the passing contrast) (commit `4127aa9`).
- **Verification caveat:** checked only against a shim in `../uo-fix-private/index.php`
  that returns 400 for `admin/`/`user/` requests with `<>"'` in any parameter. That proves
  the pins detect a fix, not that any page is fixed. **Remove the shim** when doing the
  real fix.
- **Fix direction:** intval numeric ids at read time; `utf8entities()` string ids (event
  ids, group names, user ids) on output. The test accepts either rendering the page or a
  4xx refusal.

## Data correctness

### B20 Draws and forfeits miscounted in division and archived statistics

- **Severity:** Medium.
- **Rule** (SUT `docs/ranking.md`, `docs/terminology.md`): a forfeit is recorded at 0-0 and
  `uo_game.forfeit` awards the result (1 home forfeited, 2 away forfeited, 3 both lose); a
  draw is neither a win nor a loss. `TeamStatsByPool()` (pool standings) is correct.
- **Wrong:**
  - `SeriesTeamStatsPoints()` in `lib/series.functions.php` (`seriesstatus.php`) counts by
    score only, so a 0-0 forfeit is a draw.
  - `CalcTeamStats()` in `lib/statistical.functions.php` (event archiving) counts every
    home non-win as a loss: a draw is a home loss but not an away loss, and a 0-0 forfeit
    the home team won is archived as a home loss. `TeamGames()` does not even select
    `forfeit`.
- **Test:** `tests/Integration/DrawAndForfeitStatisticsTest.php` (3 methods) (commit `b7d746d`).
- **Fix direction:** reuse `TeamStatsByPool()`'s forfeit-aware conditions; add `pp.forfeit`
  to `TeamGames()`; make home/away branches symmetric. `CalcSeasonStats()`/`CalcSeriesStats()`
  `home_wins` ignore forfeits too (unpinned, low value).

### B21 Team CSV: zero results when spirit is hidden; forfeit wins not counted

- **Severity:** Medium.
- **Where:** `TeamsToCsv()` in `lib/team.functions.php` (`ext/teamscsv.php`).
- **What happens:** the whole games/wins/goals aggregation is filtered on
  `g.show_spirit=1` — a condition meant for the SpiritPoints column — so the fixture event
  exports every team with 0 games and 0 goals. Wins are also counted by score only
  (0-0 forfeit gives no win). `ext/poolscsv.php` (via `TeamStatsByPool()`) is correct.
- **Tests:** `tests/Export/TeamsCsvResultsTest.php` (commits `48261d5`, `9297544`).
- **Fix direction:** move the `show_spirit` condition into the spirit sum
  (`SUM(IF(g.show_spirit=1 AND <event shows spirit>, score, 0))`), filter results on
  `g.hasstarted>0 AND g.isongoing=0`, and use forfeit-aware win conditions. Fix with B09.

## Low-severity robustness

### B22 Season-scoped token gets 403 on `/api/v1/games` without an event

- **Where:** `api_games_context()` in `api/v1/router.php`.
- **What happens:** with no filter, `api_games_context()` fills `CurrentSeason()` before
  `api_resolve_season_id()` can fall back to the token's scope season, so a token scoped
  to a non-current event gets `403 event_scope_mismatch`. The fallback has been dead since
  #26.
- **Test:** `tests/Api/ApiSeasonScopedTokenTest.php` (commit `6010907`).
- **Fix direction:** leave `$seasonId` empty in the final `else`, resolve, then set
  `$id = $seasonId`.

### B23 `seriesstatus` prints PHP warnings for an unknown `sort`

- **Where:** `seriesstatus.php` — the comparator indexes team rows with the raw `sort`
  parameter (`pool` is named in a branch but its column is commented out).
- **Test:** `tests/Smoke/SeriesStatusSortTest.php` (commit `dc861d5`).
- **Fix direction:** whitelist the computed keys (`against diff for games losses name
  ranking seed spirit winavg wins`).

### B24 `add_field_accounts` plugin aborts on a field name with an apostrophe

- **Where:** `plugins/add_field_accounts.php` — the one unescaped query
  (`SELECT COUNT(*) FROM uo_users WHERE userid='$name'`), a second-order SQL injection
  from reservation field names.
- **Test:** `tests/Smoke/FieldAccountsPluginTest.php` (commit `3d8fad8`).
- **Fix direction:** `sprintf(... '%s', DBEscapeString($name))`.

## Harness

### H1 HTTP tests could not flush Apache's query cache

- **Status:** fixed on branch `shared-persistent-cache` (commit `3b924c1`), matrix green.
  Not yet merged to `main`.
- **Cause:** PHPUnit runs as the host uid, Apache writes the persistent cache as
  `www-data` into a `0700` directory, so `CacheForgetPersistent()` from Smoke/Api tests
  silently removed nothing; stale rows lived for the 5 s TTL.
- **Fix:** `PERSISTENT_CACHE_DIR` points at `.runtime/cases/<id>/persistent-cache`, emptied
  and made world-writable each run (`scripts/container_runner.py`, `docs/runtime.md`).
  Existing tests using `flushQueryCaches()` (e.g. `ScoresheetHistoryPageTest`) benefit.

## Not pinned

Found but left without a test, one line each:

- Other outputs showing hidden-pool games (whether pool `visible` should gate them is a
  product decision): `ext/tournament.php`, `ext/rss.php` gameresults,
  `ext/teamcoming.php`/`teamplayed.php`, `ext/gamescsv.php`, `ext/resultscsv.php`,
  `ext/poolstatus.php`, API `divisions` (`SeasonPools($seasonId, false, true)`), and
  `gameplay`/`poolstatus` by direct id.
- RSS `gameresults` uses the `past` filter, which includes ongoing games as results.
- RSS descriptions embed names unescaped inside CDATA (`]]>` breaks out).
- `admin/schedule.php` without `reservations=` fatals (`array_flip(null)`) for a
  superadmin without the `resadmin` role.
- Many admin/user/mobile pages print PHP warnings for nonexistent ids (superadmin/logged-in).
- Menu link names (`menufunctions.php` event/installation links) and installation link
  URLs printed raw — only a superadmin can create them.
- `result.php` confirmation step prints division/pool names raw; unreachable with the
  fixture (its event has archived stats).
- Team-card and player-card `U_($season_type)`/`U_($series_type)` and `statistics.php`
  type headings printed raw (types come from fixed lists).
- `SaveSeasonPointsRoundPoints()` and `AddSeasonPointsRound()` accept teams/divisions of
  other events (writes only junk rows in the caller's own event).
- `user/respgames.php` `respgameslink()` ignores its `$htmlentities` parameter, so the
  page-menu tab is never highlighted.
- `CheckBYE()`/`CheckBYESchedule()` are unguarded but only reached after rights-checked
  calls.
- No CSRF tokens on any form (app-wide design gap).

## Checked clean

No bugs found in: event snapshot export → import → export round trip with every table
seeded (all foreign keys remap consistently); player anonymization against the SUT's
`docs/privacy.md`; Spiritkeeper token submission and escaping; Scorekeeper escaping;
password reset, session regeneration and redirects; ext/ `api_public` gating; scoreboard
figures across scorestatus, ext scoreboards, player CSV, gameplay and API.
