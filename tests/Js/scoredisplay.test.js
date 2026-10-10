"use strict";

/*
 * Score display client test.
 *
 * Exercises the SUT's `script/scoredisplay.js` under a minimal DOM/window stub,
 * a fake XMLHttpRequest and a controllable clock and timer queue: routing
 * between the game picker and the board by the URL hash, list and game
 * polling, replies to an earlier view being dropped, the local game clock and
 * its re-anchoring, the score change flash, a game that stops being public and
 * the wake lock.
 *
 * Pure client logic only: no DB, no browser, no network. Run with host Node:
 *   node tests/Js/scoredisplay.test.js [SUT_PATH]
 * SUT_PATH defaults to the sibling ../ultiorganizer checkout (or $SUT_PATH).
 */

var path = require("path");
var fs = require("fs");

var sutPath = process.argv[2] || process.env.SUT_PATH ||
  path.resolve(__dirname, "..", "..", "..", "ultiorganizer");
var scriptPath = path.join(sutPath, "script", "scoredisplay.js");
if (!fs.existsSync(scriptPath)) {
  console.error("Score display script not found at " + scriptPath +
    "\nPass the Ultiorganizer SUT path as the first argument or via $SUT_PATH.");
  process.exit(2);
}

/* --- Minimal DOM stub ----------------------------------------------------- */

function makeNode(tag, id) {
  var n = {
    tag: tag, id: id || "", children: [], className: "", hidden: false,
    style: {}, textContent: "", parentNode: null, onclick: null,
    scrollWidth: 0, clientWidth: 1000, offsetHeight: 0, clientHeight: 1000
  };
  n.appendChild = function (c) { c.parentNode = n; n.children.push(c); return c; };
  n.querySelector = function (sel) { return n.sel && n.sel[sel] ? n.sel[sel] : makeNode("div"); };
  Object.defineProperty(n, "innerHTML", {
    get: function () { return ""; },
    set: function () { n.children = []; }
  });
  return n;
}

var byId = {};
function node(id, tag) { byId[id] = makeNode(tag || "div", id); return byId[id]; }

["sd-picker", "sd-board", "sd-list", "sd-clock", "sd-back", "sd-full"].forEach(function (id) { node(id); });
byId["sd-board"].hidden = true;
byId["sd-clock"].hidden = true;
["sd-home", "sd-visitor"].forEach(function (id) {
  var team = node(id);
  team.className = "sd-team";
  team.sel = { ".sd-name": makeNode("div"), ".sd-score": makeNode("div") };
  team.appendChild(team.sel[".sd-name"]);
});

var docHandlers = {};
global.document = {
  hidden: false,
  fullscreenElement: null,
  documentElement: {},
  getElementById: function (id) { return byId[id]; },
  createElement: function (tag) { return makeNode(tag); },
  querySelector: function (sel) {
    if (sel === "#sd-home .sd-name") { return byId["sd-home"].sel[".sd-name"]; }
    if (sel === "#sd-visitor .sd-name") { return byId["sd-visitor"].sel[".sd-name"]; }
    return makeNode("div");
  },
  querySelectorAll: function () {
    return [byId["sd-home"].sel[".sd-score"], byId["sd-visitor"].sel[".sd-score"]];
  },
  addEventListener: function (ev, fn) { docHandlers[ev] = fn; }
};

/* --- Fake timers, clock, XHR, wake lock ----------------------------------- */

var fakeNow = 1000000;
Date.now = function () { return fakeNow; };

var timeouts = {};
var nextTimer = 1;
var intervals = {};
var windowHandlers = {};
var wakeRequests = [];
var lockReleased = 0;

global.navigator = {
  wakeLock: {
    request: function () {
      var p = {
        resolve: null,
        then: function (ok) { p.resolve = ok; }
      };
      wakeRequests.push(p);
      return p;
    }
  }
};

global.window = {
  SCOREDISPLAY_I18N: { ongoing: "Ongoing", upcoming: "Upcoming", noGames: "No games" },
  location: { _hash: "", get hash() { return this._hash; }, set hash(v) {
    this._hash = v ? "#" + v : "";
    if (windowHandlers.hashchange) { windowHandlers.hashchange(); }
  } },
  addEventListener: function (ev, fn) { windowHandlers[ev] = fn; },
  setTimeout: function (fn, ms) { var id = nextTimer++; timeouts[id] = { fn: fn, ms: ms }; return id; },
  clearTimeout: function (id) { delete timeouts[id]; },
  setInterval: function (fn, ms) { var id = nextTimer++; intervals[id] = { fn: fn, ms: ms }; return id; },
  clearInterval: function (id) { delete intervals[id]; },
  getComputedStyle: function () { return { fontSize: "100px" }; }
};

var requests = [];
global.XMLHttpRequest = function () {
  var xhr = this;
  xhr.open = function (method, url) { xhr.url = url; };
  xhr.send = function () { requests.push(xhr); };
};

function reply(xhr, status, body) {
  xhr.status = status;
  xhr.responseText = typeof body === "string" ? body : JSON.stringify(body);
  fakeNow += 100;
  if (status === 0) {
    xhr.onerror();
    return;
  }
  xhr.onload();
}

function pending(urlPart) {
  return requests.filter(function (r) { return !r.done && r.url.indexOf(urlPart) === 0; });
}

function answer(urlPart, status, body) {
  var list = pending(urlPart);
  var xhr = list[list.length - 1];
  xhr.done = true;
  reply(xhr, status, body);
}

function fireTimeouts() {
  var due = timeouts;
  timeouts = {};
  Object.keys(due).forEach(function (id) { due[id].fn(); });
}

function intervalCount() { return Object.keys(intervals).length; }
function tick() { Object.keys(intervals).forEach(function (id) { intervals[id].fn(); }); }

/* --- Assertions ----------------------------------------------------------- */

var failures = 0;
function check(label, actual, expected) {
  var ok = actual === expected;
  if (!ok) { failures++; }
  console.log((ok ? "PASS " : "FAIL ") + label +
    "  got=" + JSON.stringify(actual) + " want=" + JSON.stringify(expected));
}

var LIST = { games: [
  { id: 7, home: "Heat", visitor: "Tempest", homescore: 3, visitorscore: 2, ongoing: true, time: "", place: "" },
  { id: 8, home: "Blaze", visitor: "Frost", homescore: 0, visitorscore: 0, ongoing: false, time: "14:00", place: "Field 2" }
] };

function game(extra) {
  var base = { id: 7, home: "Heat", visitor: "Tempest", homescore: 3, visitorscore: 2, clock: null };
  Object.keys(extra || {}).forEach(function (k) { base[k] = extra[k]; });
  return base;
}

function lastText(id) { return byId[id].sel[".sd-score"].textContent; }
function sectionTitles() {
  return byId["sd-list"].children.map(function (s) { return s.children[0].textContent; });
}

/* --- Load: no hash shows the picker and polls the list --------------------- */

require(scriptPath);
check("picker shown without a game in the hash", byId["sd-picker"].hidden, false);
check("board hidden without a game in the hash", byId["sd-board"].hidden, true);
check("list requested on load", pending("?json=list").length, 1);

answer("?json=list", 200, LIST);
check("ongoing section before upcoming", sectionTitles().join(","), "Ongoing,Upcoming");
var ongoingButton = byId["sd-list"].children[0].children[1];
check("ongoing game button shows the score", ongoingButton.children[1].textContent, "3 - 2");
var upcomingButton = byId["sd-list"].children[1].children[1];
check("upcoming game button shows time and teams", upcomingButton.children[0].textContent, "14:00  Blaze - Frost");
check("upcoming game button shows the place", upcomingButton.children[1].textContent, "Field 2");
check("list re-polled every 15 s", timeouts[Object.keys(timeouts)[0]].ms, 15000);

fireTimeouts();
answer("?json=list", 200, { games: [] });
check("empty list says there are no games", byId["sd-list"].children[0].textContent, "No games");

fireTimeouts();
answer("?json=list", 0, null);
check("a failed poll keeps the last list", byId["sd-list"].children[0].textContent, "No games");
fireTimeouts();
answer("?json=list", 200, LIST);
fireTimeouts();
answer("?json=list", 503, { error: "maintenance" });
check("maintenance empties the list", byId["sd-list"].children[0].textContent, "No games");

/* --- Choosing a game switches to the board --------------------------------- */

fireTimeouts();
check("a list poll is in flight when the view changes", pending("?json=list").length, 1);
upcomingButton.onclick();
check("game button sets the hash", window.location.hash, "#game=8");
check("board shown", byId["sd-board"].hidden, false);
check("picker hidden", byId["sd-picker"].hidden, true);
check("wake lock requested", wakeRequests.length, 1);
check("game feed requested", pending("?json=game&game=8").length, 1);

// A reply for the list that was in flight when the view changed is dropped.
answer("?json=list", 200, LIST);
check("a late list reply does not touch the board", byId["sd-list"].children.length, 1);

/* --- Game feed: names, scores, flash --------------------------------------- */

window.location.hash = "game=7";
check("hash change re-routes to the new game", pending("?json=game&game=7").length, 1);
var staleGame = pending("?json=game&game=8")[0];
staleGame.done = true;
reply(staleGame, 200, game({ id: 8, home: "Blaze", visitor: "Frost" }));
check("a reply for the earlier game is dropped", byId["sd-home"].sel[".sd-name"].textContent, "");

answer("?json=game&game=7", 200, game());
check("home name drawn", byId["sd-home"].sel[".sd-name"].textContent, "Heat");
check("visitor name drawn", byId["sd-visitor"].sel[".sd-name"].textContent, "Tempest");
check("home score drawn", lastText("sd-home"), 3);
check("visitor score drawn", lastText("sd-visitor"), 2);
check("first reading does not flash", byId["sd-home"].className, "sd-team");
check("game polled every 3 s", timeouts[Object.keys(timeouts)[0]].ms, 3000);

fireTimeouts();
answer("?json=game&game=7", 200, game({ homescore: 4 }));
check("changed score flashes its team", byId["sd-home"].className, "sd-team sd-changed");
check("unchanged score does not flash", byId["sd-visitor"].className, "sd-team");
var flash = Object.keys(timeouts).filter(function (id) { return timeouts[id].ms === 4000; });
check("flash clears after 4 s", flash.length, 1);
timeouts[flash[0]].fn();
check("flash cleared", byId["sd-home"].className, "sd-team");

fireTimeouts();
answer("?json=game&game=7", 0, null);
check("a failed poll keeps the last score", lastText("sd-home"), 4);
check("a failed poll keeps polling", Object.keys(timeouts).length >= 1, true);

/* --- Game clock ------------------------------------------------------------ */

check("no clock without a live clock", byId["sd-clock"].hidden, true);

fakeNow = 2000000;
fireTimeouts();
answer("?json=game&game=7", 200, game({ homescore: 4, clock: { elapsed: 125, paused: false } }));
check("clock shown", byId["sd-clock"].hidden, false);
check("clock reads server elapsed (m:ss)", byId["sd-clock"].textContent, "2:05");
check("running clock ticks locally", intervalCount(), 1);
fakeNow += 10000;
tick();
check("clock advances with the wall clock", byId["sd-clock"].textContent, "2:15");

fakeNow += 3000;
fireTimeouts();
answer("?json=game&game=7", 200, game({ homescore: 4, clock: { elapsed: 137, paused: false } }));
check("a poll within a second of the local clock keeps it", byId["sd-clock"].textContent, "2:18");

fakeNow += 3000;
fireTimeouts();
answer("?json=game&game=7", 200, game({ homescore: 4, clock: { elapsed: 300, paused: false } }));
check("a drifted clock is re-anchored to the server", byId["sd-clock"].textContent, "5:00");

fireTimeouts();
answer("?json=game&game=7", 200, game({ homescore: 4, clock: { elapsed: 301, paused: true } }));
check("paused clock stops ticking", intervalCount(), 0);
check("paused clock is marked", byId["sd-clock"].className, "sd-paused");

// Setting a paused clock moves it by any amount, even within a second.
fireTimeouts();
answer("?json=game&game=7", 200, game({ homescore: 4, clock: { elapsed: 302, paused: true } }));
check("a paused clock takes every server reading", byId["sd-clock"].textContent, "5:02");

fireTimeouts();
answer("?json=game&game=7", 200, game({ homescore: 4, clock: { elapsed: 302, paused: false } }));
check("resumed clock ticks again", intervalCount(), 1);
check("resumed clock is no longer marked paused", byId["sd-clock"].className, "");

fireTimeouts();
answer("?json=game&game=7", 200, game({ homescore: 4, clock: null }));
check("clock hidden when the game has no live clock", byId["sd-clock"].hidden, true);
check("clock timer stopped", intervalCount(), 0);

/* --- Wake lock -------------------------------------------------------------- */

var lock = { release: function () { lockReleased++; }, addEventListener: function () {} };
wakeRequests[wakeRequests.length - 1].resolve(lock);
window.location.hash = "";
check("leaving the board releases the wake lock", lockReleased, 1);
check("picker shown again", byId["sd-picker"].hidden, false);

// A lock granted after the viewer left the board is released at once.
window.location.hash = "game=7";
var before = lockReleased;
window.location.hash = "";
wakeRequests[wakeRequests.length - 1].resolve(lock);
check("a lock granted after leaving is released", lockReleased, before + 1);

/* --- A game that stops being public ----------------------------------------- */

window.location.hash = "game=7";
answer("?json=game&game=7", 200, game());
check("score shown before the game is withdrawn", lastText("sd-home"), 3);
fireTimeouts();
answer("?json=game&game=7", 404, { error: "not found" });
check("a 404 returns to the picker", window.location.hash, "");
check("board hidden after a 404", byId["sd-board"].hidden, true);

window.location.hash = "game=7";
answer("?json=game&game=7", 503, { error: "maintenance" });
check("maintenance returns to the picker", window.location.hash, "");

/* --- Back and fullscreen ----------------------------------------------------- */

window.location.hash = "game=7";
byId["sd-back"].onclick();
check("back button clears the hash", window.location.hash, "");

var fullscreenCalls = 0;
document.documentElement.requestFullscreen = function () { fullscreenCalls++; };
byId["sd-full"].onclick();
check("fullscreen button requests fullscreen", fullscreenCalls, 1);
var exited = 0;
document.fullscreenElement = {};
document.exitFullscreen = function () { exited++; };
byId["sd-full"].onclick();
check("fullscreen button exits when already fullscreen", exited, 1);

console.log(failures === 0 ? "\nAll checks passed." : "\n" + failures + " check(s) failed.");
process.exit(failures === 0 ? 0 : 1);
