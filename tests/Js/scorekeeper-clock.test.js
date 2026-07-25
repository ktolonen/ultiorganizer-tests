"use strict";

/*
 * Scorekeeper client clock/double-submit-guard test.
 *
 * Exercises the SUT's `script/scorekeeper.js` (added in #86) in isolation
 * under a minimal DOM/window stub and a controllable fake clock.
 *
 * The game clock is anchored on a server-rendered elapsed-second count plus a
 * Date.now() reading taken at page load (window.scorekeeperClock), so every
 * later read is derived from a Date.now() delta rather than a setInterval
 * counter that drifts when a phone/tablet suspends timers with the screen
 * off. A second, independent IIFE guards against double-submitted forms by
 * disabling their submit controls one tick after the first submit.
 *
 * Pure client logic only: no DB, no browser, no network. Run with host Node:
 *   node tests/Js/scorekeeper-clock.test.js [SUT_PATH]
 * SUT_PATH defaults to the sibling ../ultiorganizer checkout (or $SUT_PATH).
 */

var path = require("path");
var fs = require("fs");

var sutPath = process.argv[2] || process.env.SUT_PATH ||
  path.resolve(__dirname, "..", "..", "..", "ultiorganizer");
var scriptPath = path.join(sutPath, "script", "scorekeeper.js");
if (!fs.existsSync(scriptPath)) {
  console.error("Scorekeeper script not found at " + scriptPath +
    "\nPass the Ultiorganizer SUT path as the first argument or via $SUT_PATH.");
  process.exit(2);
}

/* --- Minimal DOM / window stub ------------------------------------------- */

function node(id) {
  var n = { _t: "", id: id };
  Object.defineProperty(n, "textContent", {
    get: function () { return this._t; },
    set: function (v) { this._t = v; }
  });
  return n;
}
var elements = {};
function el(id) { if (!elements[id]) { elements[id] = node(id); } return elements[id]; }

var docHandlers = {};
var allForms = [];
global.document = {
  visibilityState: "visible",
  getElementById: el,
  addEventListener: function (ev, fn) { docHandlers[ev] = fn; },
  querySelectorAll: function (sel) {
    var m = /^form\[(.+)\]$/.exec(sel);
    if (!m) { return []; }
    var attr = m[1];
    return allForms.filter(function (f) { return f.getAttribute(attr) !== null; });
  }
};

var windowHandlers = {};
var timeouts = [];
var lastIntervalDelay = null;
global.window = {
  performance: null,
  addEventListener: function (ev, fn) { windowHandlers[ev] = fn; },
  setInterval: function (fn, delay) { lastIntervalDelay = delay; return 1; },
  clearInterval: function () {},
  setTimeout: function (fn) { timeouts.push(fn); return timeouts.length; }
};

var fakeNow = 0;
Date.now = function () { return fakeNow; };

require(scriptPath);
var clock = window.scorekeeperClock;

/* --- Assertions ----------------------------------------------------------- */

var failures = 0;
function check(label, actual, expected) {
  var ok = actual === expected;
  if (!ok) { failures++; }
  console.log((ok ? "PASS " : "FAIL ") + label +
    "  got=" + JSON.stringify(actual) + " want=" + JSON.stringify(expected));
}

/* --- isActive() before any init() ----------------------------------------- */

check("isActive false before init", clock.isActive(), false);
check("elapsedSeconds 0 before init", clock.elapsedSeconds(), 0);

/* --- Basic drift-from-Date.now, no Performance API available -------------- */

window.performance = null;
fakeNow = 100000;
clock.init({ elapsed: 40, ongoing: true, paused: false, pausedSuffix: " (Paused)" });
check("isActive true after init", clock.isActive(), true);
check("elapsedSeconds equals server value at the same instant (no perf API)", clock.elapsedSeconds(), 40);

fakeNow = 100000 + 15000; // +15s of wall-clock time passes with no re-render.
check("elapsedSeconds derives from Date.now() delta on demand", clock.elapsedSeconds(), 55);
var t = clock.time();
check("time().mm after drift", t.mm, 0);
check("time().ss after drift", t.ss, 55);

/* --- Paused freezes elapsed regardless of Date.now() ----------------------- */

fakeNow = 200000;
clock.init({ elapsed: 30, ongoing: false, paused: true, pausedSuffix: " (Paused)" });
check("paused elapsedSeconds equals anchor", clock.elapsedSeconds(), 30);
fakeNow += 50000;
check("paused elapsedSeconds ignores further Date.now() drift", clock.elapsedSeconds(), 30);

/* --- roundedTime(): 5s rounding, with 60->minute carry --------------------- */

fakeNow = 300000;
clock.init({ elapsed: 58, ongoing: false, paused: true }); // ss=58 -> round to 60
var rt = clock.roundedTime();
check("roundedTime carries the minute when seconds round up to 60", rt.mm, 1);
check("roundedTime resets seconds to 0 after carry", rt.ss, 0);

fakeNow = 310000;
clock.init({ elapsed: 42, ongoing: false, paused: true }); // ss=42 -> round to 40, no carry
rt = clock.roundedTime();
check("roundedTime rounds down to the nearest 5s", rt.ss, 40);
check("roundedTime does not carry when no rollover", rt.mm, 0);

/* --- render() writes padded text, with the paused suffix ------------------- */

fakeNow = 320000;
clock.init({ elapsed: 65, ongoing: false, paused: true, pausedSuffix: " (Paused)" });
check("render pads mm:ss and appends the paused suffix", el("gametime").textContent, "01:05 (Paused)");

/* --- serverSampleClientMs(): anchor selection / rejection rules ------------ */

// Valid anchor: credits the elapsed transfer/parse time immediately, instead
// of only picking it up on the next render tick.
window.performance = {
  timeOrigin: 400000,
  getEntriesByType: function (type) {
    return type === "navigation" ? [{ responseStart: 99000 }] : [];
  }
};
fakeNow = 500000; // anchor = 400000+99000 = 499000; now-anchor = 1000ms
clock.init({ elapsed: 10, ongoing: true, paused: false });
check("valid perf anchor credits transfer time into elapsed immediately", clock.elapsedSeconds(), 11);

// Anchor in the future (e.g. a restored/prerendered page): rejected, falls
// back to now, so no extra credit is added.
window.performance = {
  timeOrigin: 0,
  getEntriesByType: function () { return [{ responseStart: 999999999 }]; }
};
fakeNow = 600000;
clock.init({ elapsed: 20, ongoing: true, paused: false });
check("future anchor is rejected (falls back to now)", clock.elapsedSeconds(), 20);

// Anchor more than 300000ms in the past (stale/unrelated timing entry, or a
// mid-session clock change): rejected.
window.performance = {
  timeOrigin: 0,
  getEntriesByType: function () { return [{ responseStart: 1 }]; }
};
fakeNow = 1000000;
clock.init({ elapsed: 5, ongoing: true, paused: false });
check("anchor older than 300000ms is rejected (falls back to now)", clock.elapsedSeconds(), 5);

// No Performance API at all.
window.performance = null;
fakeNow = 2000000;
clock.init({ elapsed: 7, ongoing: true, paused: false });
check("missing performance falls back to now", clock.elapsedSeconds(), 7);

// Performance present but without getEntriesByType.
window.performance = {};
fakeNow = 3000000;
clock.init({ elapsed: 8, ongoing: true, paused: false });
check("performance without getEntriesByType falls back to now", clock.elapsedSeconds(), 8);

// Performance present, but no navigation entries recorded.
window.performance = {
  timeOrigin: 0,
  getEntriesByType: function () { return []; }
};
fakeNow = 4000000;
clock.init({ elapsed: 9, ongoing: true, paused: false });
check("empty navigation entries falls back to now", clock.elapsedSeconds(), 9);

/* --- render interval is only scheduled while actually ticking ------------- */

window.performance = null;
lastIntervalDelay = null;
fakeNow = 5000000;
clock.init({ elapsed: 0, ongoing: true, paused: false });
check("ongoing+unpaused clock schedules a 1s render interval", lastIntervalDelay, 1000);

lastIntervalDelay = null;
clock.init({ elapsed: 0, ongoing: false, paused: false });
check("non-ongoing clock does not schedule a render interval", lastIntervalDelay, null);

lastIntervalDelay = null;
clock.init({ elapsed: 0, ongoing: true, paused: true });
check("paused clock does not schedule a render interval", lastIntervalDelay, null);

/* --- Double-submit guard ---------------------------------------------------
 * A slow network lets a scorekeeper tap Save twice; the second submit must be
 * blocked, and controls are disabled one tick later (never synchronously),
 * since a synchronously-disabled submit button is left out of the POST body
 * and the scorekeeper pages branch on which button was pressed. */

function makeControl() { return { disabled: false }; }
function makeForm() {
  var attrs = {};
  var controls = [makeControl(), makeControl()];
  var f = {
    nodeName: "FORM",
    getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
    setAttribute: function (k, v) { attrs[k] = v; },
    removeAttribute: function (k) { delete attrs[k]; },
    querySelectorAll: function () { return controls; }
  };
  f._controls = controls;
  allForms.push(f);
  return f;
}
function dispatchSubmit(form) {
  var evt = { target: form, prevented: false, preventDefault: function () { this.prevented = true; } };
  docHandlers.submit(evt);
  return evt;
}

var form = makeForm();
var evt1 = dispatchSubmit(form);
check("first submit on a form is not prevented", evt1.prevented, false);
check("controls are not disabled synchronously", form._controls[0].disabled, false);
check("exactly one deferred disable is scheduled", timeouts.length, 1);

timeouts.pop()();
check("controls disabled once the deferred tick runs", form._controls[0].disabled, true);
check("second control disabled too", form._controls[1].disabled, true);

var evt2 = dispatchSubmit(form);
check("second submit on the same (still-busy) form is prevented", evt2.prevented, true);
check("blocked resubmit schedules no extra deferred disable", timeouts.length, 0);

windowHandlers.pageshow({ persisted: true });
check("persisted pageshow re-enables controls", form._controls[0].disabled, false);
check("persisted pageshow clears the busy attribute", form.getAttribute("data-scorekeeper-submitting"), null);

var form2 = makeForm();
dispatchSubmit(form2);
timeouts.pop()();
check("second form's controls disabled before a non-persisted pageshow", form2._controls[0].disabled, true);
windowHandlers.pageshow({ persisted: false });
check("non-persisted pageshow leaves busy forms untouched", form2._controls[0].disabled, true);

if (failures === 0) {
  console.log("\nScorekeeper clock: ALL checks passed");
  process.exit(0);
}
console.log("\nScorekeeper clock: " + failures + " FAILURE(S)");
process.exit(1);
