SET NAMES 'utf8mb4';

UPDATE uo_setting SET value='HRN2026' WHERE name='CurrentSeason';
UPDATE uo_setting SET value='yes' WHERE name='HomeTeamResponsible';
UPDATE uo_setting SET value='' WHERE name='GoogleMapsAPIKey';
UPDATE uo_setting SET value='ultiorganizer@example.com' WHERE name='EmailSource';
UPDATE uo_setting SET value='false' WHERE name='GameRSSEnabled';
UPDATE uo_setting SET value='Ultiorganizer Test Harness - ' WHERE name='PageTitle';
UPDATE uo_setting SET value='Europe/Helsinki' WHERE name='DefaultTimezone';
UPDATE uo_setting SET value='en_GB.utf8' WHERE name='DefaultLocale';
UPDATE uo_setting SET value='false' WHERE name='ShowDefenseStats';
UPDATE uo_setting SET value='admin@example.com' WHERE name='AdminEmail';
UPDATE uo_setting SET value='false' WHERE name='ShowSpiritComments';
UPDATE uo_setting SET value='false' WHERE name='ReadOnlyServer';
UPDATE uo_setting SET value='true' WHERE name='DisableVisitorLogging';

INSERT INTO uo_season (
  season_id, name, starttime, endtime, iscurrent, enrollopen, type, istournament,
  isinternational, isnationalteams, organizer, category, showspiritpoints,
  use_season_points, hide_time_on_scoresheet, event_readonly, api_public,
  timezone, spiritmode
) VALUES (
  'HRN2026', 'Harness Invitational 2026', '2026-06-01 09:00:00', '2026-06-02 18:00:00',
  1, 0, 'outdoor', 1, 0, 0, 'Harness Org', 'test', 0, 0, 0, 0, 0,
  'Europe/Helsinki', 1003
);

UPDATE uo_season SET api_public=1 WHERE season_id='HRN2026';

INSERT INTO uo_api_token (
  token_id, token_hash, token_value, label, scope_type, scope_id, revoked, created_at, last_used
) VALUES (
  900,
  '1a6918a0de39b1d923f2d4b15b44c153f2c4fbbe2bc6e82604c2b3ec37890a68',
  'harness-api-token',
  'Harness API token',
  'season',
  'HRN2026',
  0,
  '2026-01-01 00:00:00',
  NULL
);

INSERT INTO uo_series (
  series_id, name, ordering, season, valid, type, color, pool_template
) VALUES (
  100, 'Open', 'A', 'HRN2026', 1, 'open', '336699', NULL
);

INSERT INTO uo_pool (
  pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames,
  timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore,
  halftimescore, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap,
  betweenpointslen, series, type, timeslot, color, forfeitscore, forfeitagainst,
  follower, drawsallowed, playoff_template
) VALUES (
  200, 'Pool A', '1', 1, 0, 0, 2, 0,
  70, 35, 15, NULL, NULL, 1, NULL,
  NULL, 2, 'half', 1, 'soft',
  90, 100, 1, NULL, '336699', 15, 0,
  NULL, 0, NULL
);

INSERT INTO uo_team (
  team_id, name, pool, club, rank, activerank, valid, series, country, reg_id,
  sotg_token, abbreviation
) VALUES
  (300, 'Helsinki Heat', 200, NULL, 1, 1, 1, 100, 1064, NULL, NULL, 'HEAT'),
  (301, 'Tampere Tempest', 200, NULL, 2, 2, 1, 100, 1064, NULL, NULL, 'TEMP');

INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES
  (300, 200, 1, 1),
  (301, 200, 2, 2);

INSERT INTO uo_location (
  id, name, fields, indoor, address, info_fi_FI_utf8, info_en_GB_utf8, lat, lng
) VALUES (
  400, 'Harness Field Complex', 2, 0, 'Disc Park 1, Helsinki', NULL, 'Main harness venue', 60.1699000000000, 24.9384000000000
);

INSERT INTO uo_reservation (
  id, location, fieldname, reservationgroup, starttime, endtime, season, timeslots, date
) VALUES
  (500, 400, '1', 'Harness Invitational 2026', '2026-06-01 10:00:00', '2026-06-01 11:30:00', 'HRN2026', NULL, '2026-06-01 00:00:00'),
  (501, 400, '2', 'Harness Invitational 2026', '2026-06-01 14:00:00', '2026-06-01 15:30:00', 'HRN2026', NULL, '2026-06-01 00:00:00');

INSERT INTO uo_scheduling_name (scheduling_id, name) VALUES
  (600, 'Round 1'),
  (601, 'Round 2');

INSERT INTO uo_game (
  game_id, hometeam, visitorteam, homescore, visitorscore, reservation, time, valid,
  halftime, official, respteam, resppers, isongoing, scheduling_name_home, scheduling_name_visitor,
  name, timeslot, homedefenses, visitordefenses, hasstarted, islive, liveurl, timer_start,
  timer_pause_start, timer_paused_duration
) VALUES
  (700, 300, 301, 15, 11, 500, '2026-06-01 10:00:00', 1, 35, NULL, 300, NULL, 0, NULL, NULL, 600, NULL, 0, 0, 1, 0, NULL, NULL, NULL, 0),
  (701, 301, 300, NULL, NULL, 501, '2026-06-01 14:00:00', 1, 35, NULL, 301, NULL, 0, NULL, NULL, 601, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0);

INSERT INTO uo_game_pool (game, pool, timetable) VALUES
  (700, 200, 1),
  (701, 200, 1);

INSERT INTO uo_player (
  player_id, firstname, lastname, team, num, accreditation_id, accredited, reg_id, profile_id
) VALUES
  (800, 'Ari', 'Ace', 300, 8, NULL, 1, NULL, NULL),
  (801, 'Bea', 'Blade', 300, 12, NULL, 1, NULL, NULL),
  (802, 'Timo', 'Twist', 301, 7, NULL, 1, NULL, NULL),
  (803, 'Nia', 'North', 301, 14, NULL, 1, NULL, NULL);

INSERT INTO uo_played (player, game, num, accredited, acknowledged, captain) VALUES
  (800, 700, 8, 1, 1, 1),
  (801, 700, 12, 1, 1, 0),
  (802, 700, 7, 1, 1, 1),
  (803, 700, 14, 1, 1, 0);

INSERT INTO uo_goal (
  game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan, timestamp
) VALUES
  (700, 1, 801, 800, 120, 1, 0, 1, 0, '2026-06-01 10:02:00'),
  (700, 2, 803, 802, 300, 1, 1, 0, 0, '2026-06-01 10:05:00'),
  (700, 3, 800, 801, 480, 2, 1, 1, 0, '2026-06-01 10:08:00'),
  (700, 4, 802, 803, 660, 2, 2, 0, 0, '2026-06-01 10:11:00');

INSERT INTO uo_season_stats (
  season, teams, games, players, goals_total, home_wins, defenses_total
) VALUES (
  'HRN2026', 2, 2, 4, 26, 1, 0
);

INSERT INTO uo_series_stats (
  series_id, season, teams, games, players, goals_total, home_wins, defenses_total
) VALUES (
  100, 'HRN2026', 2, 2, 4, 26, 1, 0
);

INSERT INTO uo_team_stats (
  team_id, season, series, goals_made, goals_against, standing, wins, losses, defenses_total
) VALUES
  (300, 'HRN2026', 100, 15, 11, 1, 1, 0, 0),
  (301, 'HRN2026', 100, 11, 15, 2, 0, 1, 0);

INSERT INTO uo_urls (
  owner, owner_id, type, name, url, ordering, ismedialink, mediaowner, publisher_id
) VALUES
  ('season', 'HRN2026', 'menulink', 'Harness Event Site', 'https://example.com/harness', '1', 0, NULL, NULL),
  ('season', 'HRN2026', 'menumail', 'Harness Admin', 'admin@example.com', '2', 0, NULL, NULL),
  ('admin', '0', 'admin', 'Harness Admin', 'admin@example.com', '1', 0, NULL, NULL);

INSERT INTO uo_users (
  userid, password, name, email, last_login
) VALUES (
  'admin', '$2y$10$M2UtQRckO3bZAAmA8EcJceXVtZPdO1zwoW1g0glff.PstHambxtdq', 'Harness Admin', 'admin@example.com', '2026-01-01 00:00:00'
);

INSERT INTO uo_userproperties (
  userid, name, value
) VALUES
  ('admin', 'userrole', 'superadmin');
