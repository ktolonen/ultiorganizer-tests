-- A pool template for the event simulator, which builds every pool from the first template.
-- The production schema ships none. Loaded after the baseline pack by cases that generate events.
INSERT INTO uo_pooltemplate (
  template_id, name, ordering, continuingpool, teams, mvgames, timeoutlen, halftime,
  winningscore, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap, betweenpointslen,
  type, timeslot, forfeitscore, forfeitagainst, drawsallowed
) VALUES (
  9900, 'Harness template', 'A', 0, 4, 0, 70, 35,
  15, 2, 'half', 1, 'soft', 90,
  1, 70, 15, 0, 0
);
