"use strict";

/*
 * Timekeeper client engine test.
 *
 * Exercises the SUT's `script/timekeeper.js` timer engine in isolation by
 * loading it under a minimal DOM/window stub and a controllable fake clock,
 * then asserting the WFDF time-limit behaviour.
 *
 * The timekeeper signal model is "time + textual instruction": each action
 * holds an ordered list of signals (a time and a text), the highest-time signal
 * is the "play" the countdown ends on (red), and a few behaviours are keyed to
 * the action (Start of game starts the game clock; Call or discussion repeats
 * its final signal; a timeout pressed while Start of point runs inserts a fixed
 * timeout duration and continues only the still-pending point signals). This
 * test pins those behaviours so refactors of the engine stay correct.
 *
 * Pure client logic only: no DB, no browser, no network. Run with host Node:
 *   node tests/Js/timekeeper-engine.test.js [SUT_PATH]
 * SUT_PATH defaults to the sibling ../ultiorganizer checkout (or $SUT_PATH).
 */

var path = require("path");
var fs = require("fs");

var sutPath = process.argv[2] || process.env.SUT_PATH ||
  path.resolve(__dirname, "..", "..", "..", "ultiorganizer");
var enginePath = path.join(sutPath, "script", "timekeeper.js");
if (!fs.existsSync(enginePath)) {
  console.error("Timekeeper engine not found at " + enginePath +
    "\nPass the Ultiorganizer SUT path as the first argument or via $SUT_PATH.");
  process.exit(2);
}

/* --- Representative WFDF template (mirrors the seeded default) ------------- */

function sig(id, time, text) {
  return { id: id, time: time, text: text };
}
var TEMPLATES = {
  "1": {
    id: 1,
    name: "WFDF",
    caps: { half_time_cap: 55, time_cap: 100 },
    signals: {
      betweenpoints: [sig(1, 45, "Offence warning"), sig(2, 60, "Defence warning"), sig(3, 75, "Play")],
      timeout: [sig(4, 45, "Offence warning"), sig(5, 60, "Offence warning"), sig(6, 75, "Defence warning"), sig(7, 90, "Play")],
      timeoutbeforepull: [sig(8, 75, "Timeout over")],
      halftime: [sig(9, 390, "Halftime ending"), sig(10, 420, "Halftime over")],
      halfstart: [sig(11, 0, "Approaching start"), sig(12, 60, "Start of play")],
      dispute: [sig(13, 45, "Resolve call or discussion"), sig(14, 60, "Play must restart")],
      discretrieval: [sig(15, 20, "Play")]
    }
  }
};
var I18N = {
  sc_betweenpoints: "Start of point", sc_timeout: "Timeout", sc_dispute: "Call or discussion",
  sc_halfstart: "Start of game", sc_halftime: "Halftime", sc_discretrieval: "Disc retrieval",
  ui_pause: "Pause", ui_resume: "Resume", ui_mark: "Mark", ui_start_clock: "Start",
  ui_resume_clock: "Resume game clock", ui_sound_on: "Sound on", ui_sound_off: "Sound off",
  ui_seconds: "s", sig_end_timeout: "Timeout over"
};

/* --- Minimal DOM / window stub ------------------------------------------- */

function node() {
  var n = {
    _t: "", className: "", value: "", disabled: false, type: "", inputMode: "", min: "", step: "",
    children: [], _click: null, _attrs: {},
    addEventListener: function (ev, fn) { if (ev === "click") { this._click = fn; } },
    click: function () { if (this._click) { this._click.call(this); } },
    appendChild: function (c) { this.children.push(c); },
    setAttribute: function (k, v) { this._attrs[k] = v; },
    getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null; }
  };
  Object.defineProperty(n, "textContent", {
    get: function () { return this._t; },
    set: function (v) { this._t = v; if (v === "") { this.children = []; } }
  });
  Object.defineProperty(n, "innerHTML", {
    get: function () { return this.children.length ? "x" : ""; },
    set: function (v) { if (v === "" || v === "&nbsp;") { this.children = []; this._t = ""; } }
  });
  return n;
}
var elements = {};
function el(id) { if (!elements[id]) { elements[id] = node(); } return elements[id]; }
var actionHandlers = {};
var intervals = {};
function actionButton(id) {
  var b = node();
  b._attrs["data-scenario"] = id;
  b.addEventListener = function (ev, fn) { if (ev === "click") { actionHandlers[id] = fn; } };
  return b;
}
global.navigator = {};
global.document = {
  readyState: "complete",
  getElementById: el,
  addEventListener: function () {},
  createElement: function () { return node(); },
  querySelectorAll: function (sel) {
    if (sel === ".tk-action") {
      return ["betweenpoints", "timeout", "halfstart", "halftime", "dispute", "discretrieval"].map(actionButton);
    }
    return [];
  }
};
var store = {};
global.window = {
  TIMEKEEPER_TEMPLATES: TEMPLATES,
  TIMEKEEPER_I18N: I18N,
  TIMEKEEPER_DEFAULT_TEMPLATE_ID: "1",
  TIMEKEEPER_CAP_DEFAULTS: { half_time_cap: 55, time_cap: 100 },
  addEventListener: function () {},
  localStorage: {
    getItem: function (k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
    setItem: function (k, v) { store[k] = String(v); }
  },
  setInterval: function (fn, delay) { intervals[delay] = fn; return delay; },
  clearInterval: function () {}
};
var fakeNow = 0;
Date.now = function () { return fakeNow; };

require(enginePath);

/* --- Assertions ----------------------------------------------------------- */

var failures = 0;
function check(label, actual, expected) {
  var ok = actual === expected;
  if (!ok) { failures++; }
  console.log((ok ? "PASS " : "FAIL ") + label +
    "  got=" + JSON.stringify(actual) + " want=" + JSON.stringify(expected));
}
function press(id) { actionHandlers[id].call(actionButton(id)); }
function stop() { el("tk-timer-stop").click(); }
function render() { intervals[200](); }
function at(base, sec) { fakeNow = base + sec * 1000; render(); }
function isRed() { return /tk-state-zero/.test(el("tk-display").className); }
var timeText = el("tk-display-time");
var signalText = el("tk-display-signal");

/* Start of point: counts down from the highest signal (75); the final signal is
 * the red "Play", earlier signals are warnings. */
var P = 1000000;
fakeNow = P; press("betweenpoints");
at(P, 0); check("start-of-point countdown from highest signal", timeText.textContent, "1:15");
at(P, 45); check("start-of-point @45 instruction", signalText.textContent, "Offence warning");
check("start-of-point @45 not red", isRed(), false);
at(P, 75); check("start-of-point @75 instruction", signalText.textContent, "Play");
check("start-of-point @75 red", isRed(), true);
check("start-of-point @75 zero", timeText.textContent, "0:00");

/* After-pull timeout (A5.6): 45/60/75/90 timed from the call. */
P = 2000000;
stop();
fakeNow = P; press("timeout");
at(P, 0); check("timeout countdown from 90", timeText.textContent, "1:30");
at(P, 90); check("timeout @90 play", signalText.textContent, "Play");

/* Before-pull timeout: pressed while Start of point runs. The timer is set to
 * remaining-point-time + the fixed timeout duration (press-anchored); Timeout
 * over fires after the duration; only still-pending Start of point signals
 * continue (shifted by the duration); consumed ones are not repeated; and once
 * the point timer has ended a regular timeout is used. Template here:
 * Start of point 45/60/75, Timeout before pull duration 75. */

/* (a) Pressed early (30 s in): offence@45 is still pending, so it continues. */
P = 3000000;
stop();
fakeNow = P; press("betweenpoints");
at(P, 30); check("between points 45 s left", timeText.textContent, "0:45");
fakeNow = P + 30 * 1000; press("timeout");
at(P, 30); check("before-pull timer = 45 + 75 = 2:00", timeText.textContent, "2:00");
at(P, 30 + 74); check("no Timeout over before the duration", signalText.textContent, "");
at(P, 30 + 75); check("Timeout over after the duration (75 s)", signalText.textContent, "Timeout over");
check("counter shows the remaining 45 s = 0:45", timeText.textContent, "0:45");
at(P, 30 + 90); check("pending offence re-signalled", signalText.textContent, "Offence warning");
at(P, 30 + 120); check("play at zero", signalText.textContent, "Play");
check("zero", timeText.textContent, "0:00");

/* (b) Pressed late (50 s in): offence@45 already consumed -> not repeated. */
P = 4000000;
stop();
fakeNow = P; press("betweenpoints");
at(P, 50);
fakeNow = P + 50 * 1000; press("timeout");
at(P, 50); check("before-pull timer = 25 + 75 = 1:40", timeText.textContent, "1:40");
at(P, 50 + 75); check("Timeout over after the duration", signalText.textContent, "Timeout over");
at(P, 50 + 80); check("consumed offence is NOT re-signalled", signalText.textContent, "Timeout over");
at(P, 50 + 85); check("still-pending defence continues", signalText.textContent, "Defence warning");
at(P, 50 + 100); check("play at zero", signalText.textContent, "Play");

/* (c) Pressed after the point timer ended -> regular (after-pull) timeout. */
P = 5000000;
stop();
fakeNow = P; press("betweenpoints");
at(P, 80);
fakeNow = P + 80 * 1000; press("timeout");
at(P, 80); check("point ended -> regular timeout countdown 1:30", timeText.textContent, "1:30");
at(P, 80 + 90); check("regular timeout play @90", signalText.textContent, "Play");

/* Call or discussion: repeats its final signal every gap (60 - 45 = 15 s). */
P = 4000000;
stop();
fakeNow = P; press("dispute");
at(P, 60); check("dispute @60 play must restart", signalText.textContent, "Play must restart");
signalText.textContent = "(cleared)";
at(P, 75); check("dispute repeats final signal @75", signalText.textContent, "Play must restart");
signalText.textContent = "(cleared)";
at(P, 90); check("dispute repeats final signal @90", signalText.textContent, "Play must restart");

/* Start of game: the final signal starts the game clock (primary button shows
 * "Mark" once the clock is running). */
P = 5000000;
stop();
fakeNow = P; press("halfstart");
at(P, 60); check("start-of-game final signal starts game clock", el("tk-clock-primary").textContent, "Mark");

if (failures === 0) {
  console.log("\nTimekeeper engine: ALL " + "checks passed");
  process.exit(0);
}
console.log("\nTimekeeper engine: " + failures + " FAILURE(S)");
process.exit(1);
