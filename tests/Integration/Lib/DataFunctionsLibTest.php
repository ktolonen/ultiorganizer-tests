<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class DataFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('data.functions.php', 'database_only');
        LegacyApp::requireTopLevelLib('user.functions.php');

        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'false';

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'admin';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        $_SESSION['userproperties']['locale'] = 'en_US';
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    // ── RowToXML (pure, no DB) ─────────────────────────────────────────────

    public function testRowToXMLWithEndtagClosesElement(): void
    {
        $h = new EventDataXMLHandler();
        $xml = $h->RowToXML('uo_test', ['col1' => 'val1', 'col2' => 'val2'], true);

        $this->assertStringContainsString('<uo_test ', $xml);
        $this->assertStringContainsString("col1='val1'", $xml);
        $this->assertStringContainsString("col2='val2'", $xml);
        $this->assertStringEndsWith("/>\n", $xml);
    }

    public function testRowToXMLWithoutEndtagLeavesElementOpen(): void
    {
        $h = new EventDataXMLHandler();
        $xml = $h->RowToXML('uo_test', ['col1' => 'val1'], false);

        $this->assertStringEndsWith(">\n", $xml);
        $this->assertStringNotContainsString('/>', $xml);
    }

    public function testRowToXMLDefaultEndtagIsTrue(): void
    {
        $h = new EventDataXMLHandler();
        $xml = $h->RowToXML('uo_test', ['col1' => 'abc']);

        $this->assertStringEndsWith("/>\n", $xml);
    }

    public function testRowToXMLEmptyValueRenderedAsNULL(): void
    {
        $h = new EventDataXMLHandler();
        $xml = $h->RowToXML('uo_test', ['col1' => '']);

        $this->assertStringContainsString("col1='NULL'", $xml);
    }

    public function testRowToXMLSpecialCharsAreEscaped(): void
    {
        $h = new EventDataXMLHandler();
        $xml = $h->RowToXML('uo_test', ['col1' => "it's <b>fun</b> & nice"]);

        $this->assertStringContainsString('&amp;', $xml);
        $this->assertStringContainsString('&lt;', $xml);
        $this->assertStringContainsString('&#039;', $xml);
    }

    // ── end_tag ────────────────────────────────────────────────────────────

    public function testEndTagIsNoOp(): void
    {
        $h = new EventDataXMLHandler();
        $h->end_tag(null, 'UO_SEASON');
        $this->assertTrue(true);
    }

    // ── InsertRow / SetRow helpers ─────────────────────────────────────────

    public function testInsertRowAndSetRowOnSchedulingName(): void
    {
        $h = new EventDataXMLHandler();
        $newId = (int) $h->InsertRow('uo_scheduling_name', ['NAME' => 'InsertRow Test']);
        $this->assertGreaterThan(0, $newId);

        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_scheduling_name WHERE scheduling_id=$newId");
        $this->assertSame(1, $count);

        $h->SetRow('uo_scheduling_name', ['NAME' => 'SetRow Updated'], "scheduling_id=$newId");
        $name = DBQueryToValue("SELECT name FROM uo_scheduling_name WHERE scheduling_id=$newId");
        $this->assertSame('SetRow Updated', $name);

        DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$newId");
    }

    // ── EventToXML ────────────────────────────────────────────────────────

    public function testEventToXMLReturnsSeason(): void
    {
        $h = new EventDataXMLHandler();
        $xml = $h->EventToXML('HRN2026');

        $this->assertStringStartsWith("<?xml version='1.0' encoding='UTF-8'?>", $xml);
        $this->assertStringContainsString('uo_season', $xml);
        $this->assertStringContainsString('HRN2026', $xml);
        $this->assertStringContainsString('uo_series', $xml);
        $this->assertStringContainsString('uo_team', $xml);
        $this->assertStringContainsString('uo_pool', $xml);
        $this->assertStringContainsString('uo_game', $xml);
        $this->assertStringContainsString('uo_goal', $xml);
    }

    // ── InsertToDatabase simple cases (mode=new) ───────────────────────────

    public function testInsertToDatabaseSchedulingNameCreatesRow(): void
    {
        $h = new EventDataXMLHandler();
        $h->mode = 'new';
        $h->start_tag(null, 'UO_SCHEDULING_NAME', ['SCHEDULING_ID' => '8100', 'NAME' => 'Sched Import Test']);

        $this->assertArrayHasKey('8100', $h->uo_scheduling_name);
        $newId = (int) $h->uo_scheduling_name['8100'];
        $this->assertGreaterThan(0, $newId);

        $name = DBQueryToValue("SELECT name FROM uo_scheduling_name WHERE scheduling_id=$newId");
        $this->assertSame('Sched Import Test', $name);

        DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$newId");
    }

    public function testInsertToDatabaseMovingtimeCreatesRow(): void
    {
        $h = new EventDataXMLHandler();
        $h->mode = 'new';
        $h->uo_season['HRN2026'] = 'HRN2026';

        $h->start_tag(null, 'UO_MOVINGTIME', [
            'SEASON'       => 'HRN2026',
            'FROMLOCATION' => '400',
            'FROMFIELD'    => 'MT1',
            'TOLOCATION'   => '400',
            'TOFIELD'      => 'MT2',
            'TIME'         => '8',
        ]);

        $count = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_movingtime
             WHERE season='HRN2026' AND fromlocation=400 AND fromfield='MT1'
               AND tolocation=400 AND tofield='MT2'"
        );
        $this->assertSame(1, $count);

        DBQuery("DELETE FROM uo_movingtime WHERE season='HRN2026' AND fromlocation=400 AND fromfield='MT1'");
    }

    // ── InsertToDatabase full-chain (mode=new) ────────────────────────────

    public function testInsertToDatabaseFullImport(): void
    {
        // Pre-clean any leftovers from prior failed runs
        DBQuery("DELETE FROM uo_season WHERE season_id='TTST01'");

        $h = new EventDataXMLHandler();
        $h->mode = 'new';

        // uo_season ─────────────────────────────────────────────────────────
        $h->start_tag(null, 'UO_SEASON', [
            'SEASON_ID'               => 'TTST01',
            'NAME'                    => 'Harness Full Import Test',
            'STARTTIME'               => '2026-07-01 09:00:00',
            'ENDTIME'                 => '2026-07-01 18:00:00',
            'ISCURRENT'               => '0',
            'ENROLLOPEN'              => '0',
            'TYPE'                    => 'outdoor',
            'ISTOURNAMENT'            => '1',
            'ISINTERNATIONAL'         => '0',
            'ISNATIONALTEAMS'         => '0',
            'ORGANIZER'               => 'NULL',
            'CATEGORY'                => 'test',
            'SHOWSPIRITPOINTS'        => '0',
            'USE_SEASON_POINTS'       => '0',
            'HIDE_TIME_ON_SCORESHEET' => '0',
            'EVENT_READONLY'          => '0',
            'API_PUBLIC'              => '0',
        ]);
        $seasonId = (string) $h->uo_season['TTST01'];

        $newSeriesId = 0;
        $newSchedId  = 0;
        $newTeamId   = 0;
        $newPlayerId = 0;
        $newPoolId   = 0;
        $newResvId   = 0;
        $newGameId   = 0;

        try {
            // uo_series ─────────────────────────────────────────────────────
            $h->start_tag(null, 'UO_SERIES', [
                'SERIES_ID'     => '8001',
                'NAME'          => 'Import Division',
                'ORDERING'      => 'A',
                'SEASON'        => 'TTST01',
                'VALID'         => '1',
                'TYPE'          => 'open',
                'COLOR'         => '336699',
                'POOL_TEMPLATE' => 'NULL',
            ]);
            $newSeriesId = (int) $h->uo_series['8001'];

            // uo_scheduling_name ────────────────────────────────────────────
            $h->start_tag(null, 'UO_SCHEDULING_NAME', [
                'SCHEDULING_ID' => '8001',
                'NAME'          => 'Round Import',
            ]);
            $newSchedId = (int) $h->uo_scheduling_name['8001'];

            // uo_team ───────────────────────────────────────────────────────
            $h->start_tag(null, 'UO_TEAM', [
                'TEAM_ID'      => '8001',
                'NAME'         => 'Import Team',
                'POOL'         => 'NULL',
                'CLUB'         => 'NULL',
                'RANK'         => '1',
                'ACTIVERANK'   => '1',
                'VALID'        => '1',
                'SERIES'       => '8001',
                'COUNTRY'      => 'NULL',
                'REG_ID'       => 'NULL',
                'SOTG_TOKEN'   => 'NULL',
                'ABBREVIATION' => 'IMP',
            ]);
            $newTeamId = (int) $h->uo_team['8001'];

            // uo_player ─────────────────────────────────────────────────────
            $h->start_tag(null, 'UO_PLAYER', [
                'PLAYER_ID'        => '8001',
                'FIRSTNAME'        => 'Import',
                'LASTNAME'         => 'Player',
                'TEAM'             => '8001',
                'NUM'              => '1',
                'ACCREDITATION_ID' => 'NULL',
                'ACCREDITED'       => '0',
                'REG_ID'           => 'NULL',
                'PROFILE_ID'       => 'NULL',
            ]);
            $newPlayerId = (int) $h->uo_player['8001'];

            // uo_pool ───────────────────────────────────────────────────────
            $h->start_tag(null, 'UO_POOL', [
                'POOL_ID'          => '8001',
                'NAME'             => 'Import Pool',
                'ORDERING'         => '1',
                'VISIBLE'          => '1',
                'CONTINUINGPOOL'   => '0',
                'PLACEMENTPOOL'    => '0',
                'TEAMS'            => '2',
                'MVGAMES'          => '0',
                'TIMEOUTLEN'       => '70',
                'HALFTIME'         => '35',
                'WINNINGSCORE'     => '15',
                'TIMECAP'          => 'NULL',
                'SCORECAP'         => 'NULL',
                'PLAYED'           => '1',
                'ADDSCORE'         => 'NULL',
                'HALFTIMESCORE'    => 'NULL',
                'TIMEOUTS'         => '2',
                'TIMEOUTSPER'      => 'half',
                'TIMEOUTSOVERTIME' => '1',
                'TIMEOUTSTIMECAP'  => 'soft',
                'BETWEENPOINTSLEN' => '90',
                'SERIES'           => '8001',
                'TYPE'             => '1',
                'TIMESLOT'         => 'NULL',
                'COLOR'            => 'NULL',
                'FORFEITSCORE'     => '15',
                'FORFEITAGAINST'   => '0',
                'FOLLOWER'         => 'NULL',
                'DRAWSALLOWED'     => '0',
                'PLAYOFF_TEMPLATE' => 'NULL',
            ]);
            $newPoolId = (int) $h->uo_pool['8001'];

            // uo_team_pool ──────────────────────────────────────────────────
            $h->start_tag(null, 'UO_TEAM_POOL', [
                'TEAM'       => '8001',
                'POOL'       => '8001',
                'RANK'       => '1',
                'ACTIVERANK' => '1',
            ]);

            // uo_reservation ────────────────────────────────────────────────
            $h->start_tag(null, 'UO_RESERVATION', [
                'ID'               => '8001',
                'LOCATION'         => '400',
                'FIELDNAME'        => 'I1',
                'RESERVATIONGROUP' => 'Import Group',
                'STARTTIME'        => '2026-07-01 10:00:00',
                'ENDTIME'          => '2026-07-01 11:30:00',
                'SEASON'           => 'TTST01',
            ]);
            $newResvId = (int) $h->uo_reservation['8001'];

            // uo_movingtime ─────────────────────────────────────────────────
            $h->start_tag(null, 'UO_MOVINGTIME', [
                'SEASON'       => 'TTST01',
                'FROMLOCATION' => '400',
                'FROMFIELD'    => 'IF1',
                'TOLOCATION'   => '400',
                'TOFIELD'      => 'IF2',
                'TIME'         => '10',
            ]);

            // uo_game ───────────────────────────────────────────────────────
            $h->start_tag(null, 'UO_GAME', [
                'GAME_ID'                 => '8001',
                'HOMETEAM'                => '8001',
                'VISITORTEAM'             => '8001',
                'HOMESCORE'               => 'NULL',
                'VISITORSCORE'            => 'NULL',
                'RESERVATION'             => '8001',
                'TIME'                    => '2026-07-01 10:00:00',
                'VALID'                   => '1',
                'HALFTIME'                => '35',
                'OFFICIAL'                => 'NULL',
                'RESPTEAM'                => '8001',
                'RESPPERS'                => 'NULL',
                'ISONGOING'               => '0',
                'SCHEDULING_NAME_HOME'    => 'NULL',
                'SCHEDULING_NAME_VISITOR' => 'NULL',
                'NAME'                    => 'NULL',
                'TIMESLOT'                => 'NULL',
                'HOMEDEFENSES'            => '0',
                'VISITORDEFENSES'         => '0',
                'HASSTARTED'              => '0',
                'ISLIVE'                  => '0',
                'LIVEURL'                 => 'NULL',
                'TIMER_START'             => 'NULL',
                'TIMER_PAUSE_START'       => 'NULL',
                'TIMER_PAUSED_DURATION'   => '0',
            ]);
            $newGameId = (int) $h->uo_game['8001'];

            // uo_goal ───────────────────────────────────────────────────────
            $h->start_tag(null, 'UO_GOAL', [
                'GAME'         => '8001',
                'NUM'          => '1',
                'ASSIST'       => '8001',
                'SCORER'       => '8001',
                'TIME'         => '120',
                'HOMESCORE'    => '1',
                'VISITORSCORE' => '0',
                'ISHOMEGOAL'   => '1',
                'ISCALLAHAN'   => '0',
            ]);

            // uo_gameevent ──────────────────────────────────────────────────
            $h->start_tag(null, 'UO_GAMEEVENT', [
                'GAME'   => '8001',
                'NUM'    => '1',
                'TIME'   => '60',
                'TYPE'   => 'timeout',
                'ISHOME' => '1',
                'INFO'   => 'NULL',
            ]);

            // uo_played ─────────────────────────────────────────────────────
            $h->start_tag(null, 'UO_PLAYED', [
                'GAME'           => '8001',
                'PLAYER'         => '8001',
                'NUM'            => '1',
                'ACCREDITED'     => '0',
                'ACKNOWLEDGED'   => '0',
                'CAPTAIN'        => '0',
                'SPIRIT_CAPTAIN' => '0',
            ]);

            // uo_game_pool (timetable=0 → InsertRow path) ───────────────────
            $h->start_tag(null, 'UO_GAME_POOL', [
                'GAME'      => '8001',
                'POOL'      => '8001',
                'TIMETABLE' => '0',
            ]);

            // uo_moveteams ──────────────────────────────────────────────────
            $h->start_tag(null, 'UO_MOVETEAMS', [
                'FROMPOOL'      => '8001',
                'TOPOOL'        => '8001',
                'FROMPLACING'   => '1',
                'TORANK'        => '1',
                'ISMOVED'       => '0',
                'SCHEDULING_ID' => '8001',
            ]);

            $this->assertNotEmpty($seasonId);
            $this->assertGreaterThan(0, $newSeriesId);
            $this->assertGreaterThan(0, $newTeamId);
            $this->assertGreaterThan(0, $newPlayerId);
            $this->assertGreaterThan(0, $newPoolId);
            $this->assertGreaterThan(0, $newGameId);

            $seasonRow = DBQueryToValue("SELECT COUNT(*) FROM uo_season WHERE season_id='$seasonId'");
            $this->assertSame(1, (int) $seasonRow);

            $gameRow = DBQueryToValue("SELECT COUNT(*) FROM uo_game WHERE game_id=$newGameId");
            $this->assertSame(1, (int) $gameRow);

        } finally {
            if ($newGameId > 0) {
                DBQuery("DELETE FROM uo_game WHERE game_id=$newGameId");
            }
            if ($newPoolId > 0) {
                DBQuery("DELETE FROM uo_moveteams WHERE frompool=$newPoolId");
                DBQuery("DELETE FROM uo_team_pool WHERE pool=$newPoolId");
                DBQuery("DELETE FROM uo_pool WHERE pool_id=$newPoolId");
            }
            if ($newPlayerId > 0) {
                DBQuery("DELETE FROM uo_player WHERE player_id=$newPlayerId");
            }
            if ($newTeamId > 0) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$newTeamId");
            }
            if ($newResvId > 0) {
                DBQuery("DELETE FROM uo_reservation WHERE id=$newResvId");
            }
            DBQuery("DELETE FROM uo_movingtime WHERE season='" . DBEscapeString($seasonId) . "'");
            if ($newSchedId > 0) {
                DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$newSchedId");
            }
            if ($newSeriesId > 0) {
                DBQuery("DELETE FROM uo_series WHERE series_id=$newSeriesId");
            }
            $safeSeasonId = DBEscapeString($seasonId);
            DBQuery("DELETE FROM uo_userproperties WHERE userid='admin' AND name='editseason' AND value='$safeSeasonId'");
            DBQuery("DELETE FROM uo_season WHERE season_id='$safeSeasonId'");
        }
    }

    // ── ReplaceInDatabase comprehensive test ──────────────────────────────

    public function testReplaceInDatabaseCoversFixtureDataPaths(): void
    {
        // Covers ReplaceInDatabase cases: uo_team, uo_player, uo_pool,
        // uo_reservation, uo_game, uo_goal, uo_gameevent, uo_played,
        // uo_team_pool, uo_game_pool, uo_moveteams using fixture data.
        // "exists" paths call SetRow; "not-exists" paths call InsertRow.
        // SetRow wraps all values in single quotes, so nullable int columns
        // that hold SQL NULL in the DB are omitted from these rows.

        $h = new EventDataXMLHandler();
        $h->mode = 'replace';
        $h->eventId = 'HRN2026';

        // Pre-populate id maps (as if uo_season and uo_series were processed)
        $h->uo_season['HRN2026']      = 'HRN2026';
        $h->uo_series['100']          = '100';
        $h->uo_scheduling_name['600'] = '600';
        $h->uo_team['300']            = '300';
        $h->uo_team['301']            = '301';
        $h->uo_player['800']          = '800';
        $h->uo_player['801']          = '801';
        $h->uo_pool['200']            = '200';
        $h->uo_reservation['500']     = '500';
        $h->uo_game['700']            = '700';

        // uo_team (exists → SetRow) ─────────────────────────────────────────
        // Omit club, reg_id, sotg_token (all int/nullable with SQL NULL).
        $h->start_tag(null, 'UO_TEAM', [
            'TEAM_ID'      => '300',
            'NAME'         => 'Helsinki Heat',
            'POOL'         => '200',
            'RANK'         => '1',
            'ACTIVERANK'   => '1',
            'VALID'        => '1',
            'SERIES'       => '100',
            'COUNTRY'      => '1064',
            'ABBREVIATION' => 'HEAT',
        ]);
        $this->assertSame('300', $h->uo_team['300']);

        // uo_player (exists → maps TEAM, then SetRow) ──────────────────────
        // Omit accreditation_id, reg_id, profile_id (int/nullable with NULL).
        $h->start_tag(null, 'UO_PLAYER', [
            'PLAYER_ID'  => '800',
            'FIRSTNAME'  => 'Ari',
            'LASTNAME'   => 'Ace',
            'TEAM'       => '300',
            'NUM'        => '8',
            'ACCREDITED' => '1',
        ]);
        $this->assertSame('800', $h->uo_player['800']);

        // uo_pool (exists → maps SERIES, then SetRow) ──────────────────────
        // Omit timecap, scorecap, addscore, halftimescore, timeslot, follower
        // (all int/nullable with SQL NULL in fixture).
        $h->start_tag(null, 'UO_POOL', [
            'POOL_ID'          => '200',
            'NAME'             => 'Pool A',
            'ORDERING'         => '1',
            'VISIBLE'          => '1',
            'CONTINUINGPOOL'   => '0',
            'PLACEMENTPOOL'    => '0',
            'TEAMS'            => '2',
            'MVGAMES'          => '0',
            'TIMEOUTLEN'       => '70',
            'HALFTIME'         => '35',
            'WINNINGSCORE'     => '15',
            'PLAYED'           => '1',
            'TIMEOUTS'         => '2',
            'TIMEOUTSPER'      => 'half',
            'TIMEOUTSOVERTIME' => '1',
            'TIMEOUTSTIMECAP'  => 'soft',
            'BETWEENPOINTSLEN' => '90',
            'SERIES'           => '100',
            'TYPE'             => '1',
            'COLOR'            => '336699',
            'FORFEITSCORE'     => '15',
            'FORFEITAGAINST'   => '0',
            'DRAWSALLOWED'     => '0',
        ]);
        $this->assertSame('200', $h->uo_pool['200']);

        // uo_reservation (exists → maps SEASON, then SetRow) ───────────────
        // Omit date (datetime, MySQL strict rejects string 'NULL' for datetime).
        $h->start_tag(null, 'UO_RESERVATION', [
            'ID'               => '500',
            'LOCATION'         => '400',
            'FIELDNAME'        => '1',
            'RESERVATIONGROUP' => 'Harness Invitational 2026',
            'STARTTIME'        => '2026-06-01 10:00:00',
            'ENDTIME'          => '2026-06-01 11:30:00',
            'SEASON'           => 'HRN2026',
        ]);
        $this->assertSame('500', $h->uo_reservation['500']);

        // uo_game (exists → maps HOMETEAM/VISITORTEAM/RESPTEAM/RESERVATION, SetRow)
        // Omit resppers, scheduling_name_home/_visitor, timeslot, timer_start,
        // timer_pause_start (all int/nullable with SQL NULL in fixture).
        $h->start_tag(null, 'UO_GAME', [
            'GAME_ID'               => '700',
            'HOMETEAM'              => '300',
            'VISITORTEAM'           => '301',
            'HOMESCORE'             => '15',
            'VISITORSCORE'          => '11',
            'RESERVATION'           => '500',
            'TIME'                  => '2026-06-01 10:00:00',
            'VALID'                 => '1',
            'HALFTIME'              => '35',
            'RESPTEAM'              => '300',
            'ISONGOING'             => '0',
            'NAME'                  => '600',
            'HOMEDEFENSES'          => '0',
            'VISITORDEFENSES'       => '0',
            'HASSTARTED'            => '1',
            'ISLIVE'                => '0',
            'TIMER_PAUSED_DURATION' => '0',
        ]);
        $this->assertSame('700', $h->uo_game['700']);

        // uo_goal (exists → maps GAME/ASSIST/SCORER, then SetRow) ─────────
        $h->start_tag(null, 'UO_GOAL', [
            'GAME'         => '700',
            'NUM'          => '1',
            'ASSIST'       => '801',
            'SCORER'       => '800',
            'TIME'         => '120',
            'HOMESCORE'    => '1',
            'VISITORSCORE' => '0',
            'ISHOMEGOAL'   => '1',
            'ISCALLAHAN'   => '0',
        ]);

        // uo_gameevent (not exists → InsertRow, needs cleanup) ─────────────
        $h->start_tag(null, 'UO_GAMEEVENT', [
            'GAME'   => '700',
            'NUM'    => '99',
            'TIME'   => '30',
            'TYPE'   => 'timeout',
            'ISHOME' => '1',
            'INFO'   => 'test',
        ]);
        $gevCount = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_gameevent WHERE game=700 AND num=99"
        );
        $this->assertSame(1, $gevCount);

        // uo_played (exists → maps GAME/PLAYER, then SetRow) ───────────────
        $h->start_tag(null, 'UO_PLAYED', [
            'GAME'           => '700',
            'PLAYER'         => '800',
            'NUM'            => '8',
            'ACCREDITED'     => '1',
            'ACKNOWLEDGED'   => '1',
            'CAPTAIN'        => '1',
            'SPIRIT_CAPTAIN' => '0',
        ]);

        // uo_team_pool (exists → maps TEAM/POOL, then SetRow) ──────────────
        $h->start_tag(null, 'UO_TEAM_POOL', [
            'TEAM'       => '300',
            'POOL'       => '200',
            'RANK'       => '1',
            'ACTIVERANK' => '1',
        ]);

        // uo_game_pool (timetable=1 → SetGamePool path) ────────────────────
        $h->start_tag(null, 'UO_GAME_POOL', [
            'GAME'      => '700',
            'POOL'      => '200',
            'TIMETABLE' => '1',
        ]);

        // uo_moveteams (not exists → InsertRow, needs cleanup) ─────────────
        $h->start_tag(null, 'UO_MOVETEAMS', [
            'FROMPOOL'      => '200',
            'TOPOOL'        => '200',
            'FROMPLACING'   => '97',
            'TORANK'        => '97',
            'ISMOVED'       => '0',
            'SCHEDULING_ID' => '600',
        ]);
        $mtCount = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_moveteams WHERE frompool=200 AND fromplacing=97"
        );
        $this->assertSame(1, $mtCount);

        // Cleanup inserted rows
        DBQuery("DELETE FROM uo_gameevent WHERE game=700 AND num=99");
        DBQuery("DELETE FROM uo_moveteams WHERE frompool=200 AND fromplacing=97");
    }

    // ── ReplaceInDatabase individual tests ─────────────────────────────────

    public function testReplaceInDatabaseSchedulingNameExistsUpdates(): void
    {
        $h = new EventDataXMLHandler();
        $newId = (int) $h->InsertRow('uo_scheduling_name', ['NAME' => 'Before Replace']);

        try {
            $h->mode = 'replace';
            $h->start_tag(null, 'UO_SCHEDULING_NAME', [
                'SCHEDULING_ID' => (string) $newId,
                'NAME'          => 'After Replace',
            ]);

            $name = DBQueryToValue("SELECT name FROM uo_scheduling_name WHERE scheduling_id=$newId");
            $this->assertSame('After Replace', $name);
        } finally {
            DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$newId");
        }
    }

    public function testReplaceInDatabaseSchedulingNameNotExistsInserts(): void
    {
        $h = new EventDataXMLHandler();
        $h->mode = 'replace';
        $h->start_tag(null, 'UO_SCHEDULING_NAME', [
            'SCHEDULING_ID' => '7777',
            'NAME'          => 'Not Exists Insert',
        ]);

        $this->assertArrayHasKey('7777', $h->uo_scheduling_name);
        $newId = (int) $h->uo_scheduling_name['7777'];

        try {
            $count = (int) DBQueryToValue(
                "SELECT COUNT(*) FROM uo_scheduling_name WHERE scheduling_id=$newId AND name='Not Exists Insert'"
            );
            $this->assertSame(1, $count);
        } finally {
            DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$newId");
        }
    }

    public function testReplaceInDatabaseMovingtimeExistsUpdates(): void
    {
        $h = new EventDataXMLHandler();
        $h->uo_season['HRN2026'] = 'HRN2026';

        $h->mode = 'new';
        $h->start_tag(null, 'UO_MOVINGTIME', [
            'SEASON'       => 'HRN2026',
            'FROMLOCATION' => '400',
            'FROMFIELD'    => 'RM1',
            'TOLOCATION'   => '400',
            'TOFIELD'      => 'RM2',
            'TIME'         => '5',
        ]);

        try {
            $h->mode = 'replace';
            $h->start_tag(null, 'UO_MOVINGTIME', [
                'SEASON'       => 'HRN2026',
                'FROMLOCATION' => '400',
                'FROMFIELD'    => 'RM1',
                'TOLOCATION'   => '400',
                'TOFIELD'      => 'RM2',
                'TIME'         => '20',
            ]);

            $time = DBQueryToValue(
                "SELECT time FROM uo_movingtime
                 WHERE season='HRN2026' AND fromlocation=400 AND fromfield='RM1'"
            );
            $this->assertSame('20', (string) $time);
        } finally {
            DBQuery("DELETE FROM uo_movingtime WHERE season='HRN2026' AND fromlocation=400 AND fromfield='RM1'");
        }
    }

    public function testReplaceInDatabaseMovingtimeNotExistsInserts(): void
    {
        $h = new EventDataXMLHandler();
        $h->mode = 'replace';
        $h->uo_season['HRN2026'] = 'HRN2026';

        $h->start_tag(null, 'UO_MOVINGTIME', [
            'SEASON'       => 'HRN2026',
            'FROMLOCATION' => '400',
            'FROMFIELD'    => 'RN1',
            'TOLOCATION'   => '400',
            'TOFIELD'      => 'RN2',
            'TIME'         => '15',
        ]);

        try {
            $count = (int) DBQueryToValue(
                "SELECT COUNT(*) FROM uo_movingtime
                 WHERE season='HRN2026' AND fromlocation=400 AND fromfield='RN1'"
            );
            $this->assertSame(1, $count);
        } finally {
            DBQuery("DELETE FROM uo_movingtime WHERE season='HRN2026' AND fromlocation=400 AND fromfield='RN1'");
        }
    }

    public function testReplaceInDatabaseSeriesExistsUpdates(): void
    {
        $h = new EventDataXMLHandler();
        // Omit POOL_TEMPLATE (int nullable): SetRow wraps all values in quotes
        // and MySQL strict mode rejects string 'NULL' for integer columns.
        $newSeriesId = (int) $h->InsertRow('uo_series', [
            'NAME'     => 'Before Series Replace',
            'ORDERING' => 'Z',
            'SEASON'   => 'HRN2026',
            'VALID'    => '1',
            'TYPE'     => 'open',
            'COLOR'    => 'NULL',
        ]);

        try {
            $h->mode = 'replace';
            $h->uo_season['HRN2026'] = 'HRN2026';
            $h->start_tag(null, 'UO_SERIES', [
                'SERIES_ID' => (string) $newSeriesId,
                'NAME'      => 'After Series Replace',
                'ORDERING'  => 'Z',
                'SEASON'    => 'HRN2026',
                'VALID'     => '1',
                'TYPE'      => 'open',
                'COLOR'     => 'NULL',
            ]);

            $name = DBQueryToValue("SELECT name FROM uo_series WHERE series_id=$newSeriesId");
            $this->assertSame('After Series Replace', $name);
            $this->assertSame($newSeriesId, (int) ($h->uo_series[(string) $newSeriesId] ?? 0));
        } finally {
            DBQuery("DELETE FROM uo_series WHERE series_id=$newSeriesId");
        }
    }

    public function testReplaceInDatabaseSeriesNotExistsInserts(): void
    {
        $h = new EventDataXMLHandler();
        $h->mode = 'replace';
        $h->uo_season['HRN2026'] = 'HRN2026';

        $h->start_tag(null, 'UO_SERIES', [
            'SERIES_ID'     => '9001',
            'NAME'          => 'Not Exists Series',
            'ORDERING'      => 'Z',
            'SEASON'        => 'HRN2026',
            'VALID'         => '1',
            'TYPE'          => 'open',
            'COLOR'         => 'NULL',
            'POOL_TEMPLATE' => 'NULL',
        ]);

        $this->assertArrayHasKey('9001', $h->uo_series);
        $newId = (int) $h->uo_series['9001'];

        try {
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_series WHERE series_id=$newId");
            $this->assertSame(1, $count);
        } finally {
            DBQuery("DELETE FROM uo_series WHERE series_id=$newId");
        }
    }

    public function testReplaceInDatabaseSeasonExistsUpdates(): void
    {
        DBQuery(
            "INSERT INTO uo_season"
            . " (season_id, name, iscurrent, enrollopen, istournament, isinternational,"
            . " isnationalteams, showspiritpoints, use_season_points, hide_time_on_scoresheet,"
            . " event_readonly, api_public)"
            . " VALUES ('TTSTRS', 'Replace Season Before', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)"
        );

        try {
            $h = new EventDataXMLHandler();
            $h->mode = 'replace';
            $h->eventId = 'TTSTRS';

            $h->start_tag(null, 'UO_SEASON', [
                'SEASON_ID'               => 'TTSTRS',
                'NAME'                    => 'Replace Season After',
                'STARTTIME'               => '2026-08-01 09:00:00',
                'ENDTIME'                 => '2026-08-01 18:00:00',
                'ISCURRENT'               => '0',
                'ENROLLOPEN'              => '0',
                'TYPE'                    => 'outdoor',
                'ISTOURNAMENT'            => '0',
                'ISINTERNATIONAL'         => '0',
                'ISNATIONALTEAMS'         => '0',
                'SHOWSPIRITPOINTS'        => '0',
                'USE_SEASON_POINTS'       => '0',
                'HIDE_TIME_ON_SCORESHEET' => '0',
                'EVENT_READONLY'          => '0',
                'API_PUBLIC'              => '0',
            ]);

            $name = DBQueryToValue("SELECT name FROM uo_season WHERE season_id='TTSTRS'");
            $this->assertSame('Replace Season After', $name);
            $this->assertSame('TTSTRS', $h->uo_season['TTSTRS']);
        } finally {
            DBQuery("DELETE FROM uo_season WHERE season_id='TTSTRS'");
        }
    }
}
