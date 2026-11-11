/* ZAS Attendance — the phone's own storage (IndexedDB).
 *
 * Everything the app must not lose lives here, and the rule that matters most
 * is in enqueue(): an event is written, with its sequence number, in ONE
 * transaction, and the caller is told "saved" only when that transaction has
 * COMPLETED — not when the put() was requested. Blueprint §3: "Store locally
 * created events transactionally in IndexedDB before confirming saved."
 *
 * Explicit transaction code, no library: the whole file is small enough to
 * read, and there is no dependency to go stale on a gate phone.
 *
 * WHAT THIS CANNOT PROMISE. IndexedDB lives inside the browser. If the phone
 * is lost or reset, or its browser data is cleared, or the OS evicts storage
 * under pressure, events that had not reached the server are gone. The app
 * asks for persistent storage to make eviction unlikely; it cannot make loss
 * impossible, and nothing in the interface says otherwise.
 */
(function (global) {
  'use strict';

  var DB_NAME = 'zas-attendance';
  var DB_VERSION = 2;      // 2: adds 'flags' (Capture & Flag, with its still)
  var dbPromise = null;

  function open() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise(function (resolve, reject) {
      var req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'key' });
        if (!db.objectStoreNames.contains('outbox')) {
          var ob = db.createObjectStore('outbox', { keyPath: 'event_uuid' });
          ob.createIndex('by_seq', 'device_sequence', { unique: true });
          ob.createIndex('by_state', 'state', { unique: false });
        }
        if (!db.objectStoreNames.contains('employees')) db.createObjectStore('employees', { keyPath: 'code' });
        if (!db.objectStoreNames.contains('cycles')) db.createObjectStore('cycles', { keyPath: 'entry_cycle_id' });
        /* A flag and, until it has been sent, its still. The ONLY place a
           picture is ever kept on this phone: employee photos are shown from
           the server and never stored here. */
        if (!db.objectStoreNames.contains('flags')) db.createObjectStore('flags', { keyPath: 'flag_uuid' });
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { dbPromise = null; reject(req.error); };
      req.onblocked = function () { reject(new Error('Storage is blocked by another open copy of this app.')); };
    });
    return dbPromise;
  }

  /* One transaction; resolves on COMPLETE, rejects on abort or error. */
  function tx(stores, mode, work) {
    return open().then(function (db) {
      return new Promise(function (resolve, reject) {
        var t;
        try {
          // 'strict' asks the browser to flush to disk before reporting
          // success. Browsers that do not know the option ignore it.
          t = db.transaction(stores, mode, mode === 'readwrite' ? { durability: 'strict' } : undefined);
        } catch (e) { t = db.transaction(stores, mode); }
        var out;
        t.oncomplete = function () { resolve(out); };
        t.onabort = function () { reject(t.error || new Error('Storage transaction aborted.')); };
        t.onerror = function () { reject(t.error); };
        try { out = work(t); } catch (e) { try { t.abort(); } catch (_) {} reject(e); }
      });
    });
  }

  function reqToPromise(req) {
    return new Promise(function (resolve, reject) {
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
  }

  // ------------------------------------------------------------------ meta
  function getMeta(key) {
    var value;
    return tx(['meta'], 'readonly', function (t) {
      t.objectStore('meta').get(key).onsuccess = function (e) { value = e.target.result ? e.target.result.value : undefined; };
    }).then(function () { return value; });
  }
  function setMeta(key, value) {
    return tx(['meta'], 'readwrite', function (t) { t.objectStore('meta').put({ key: key, value: value }); });
  }

  // ---------------------------------------------------------------- outbox
  /* Save an event. The sequence number is taken and the event is written in
     the SAME transaction, so two taps in quick succession can never share a
     number and a crash can never burn a number without an event behind it. */
  function enqueue(event) {
    var saved;
    return tx(['meta', 'outbox'], 'readwrite', function (t) {
      var meta = t.objectStore('meta');
      meta.get('next_seq').onsuccess = function (e) {
        var seq = (e.target.result ? e.target.result.value : 0) + 1;
        meta.put({ key: 'next_seq', value: seq });
        saved = Object.assign({}, event, { device_sequence: seq, state: 'pending', attempts: 0, created_at: Date.now() });
        t.objectStore('outbox').add(saved);
      };
    }).then(function () { return saved; });
  }

  function allOutbox() {
    var rows = [];
    return tx(['outbox'], 'readonly', function (t) {
      t.objectStore('outbox').index('by_seq').openCursor().onsuccess = function (e) {
        var c = e.target.result;
        if (c) { rows.push(c.value); c.continue(); }
      };
    }).then(function () { return rows; });
  }

  function pending() {
    return allOutbox().then(function (rows) { return rows.filter(function (r) { return r.state === 'pending'; }); });
  }

  /* Apply the server's answers. An event leaves 'pending' ONLY here, and only
     with a receipt in hand — "Do not delete local events until server
     acknowledgment." Acknowledged events are kept (state 'acked'), not
     deleted, so the gatekeeper can still see what was sent today. */
  function applyReceipts(receipts) {
    return tx(['outbox'], 'readwrite', function (t) {
      var store = t.objectStore('outbox');
      receipts.forEach(function (r) {
        if (!r || !r.event_uuid) return;
        store.get(r.event_uuid).onsuccess = function (e) {
          var row = e.target.result;
          if (!row) return;
          row.receipt = r;
          row.attempts = (row.attempts || 0) + 1;
          row.answered_at = Date.now();
          if (r.receipt_status === 'RETRY') {
            row.state = 'pending';                 // not settled: stays queued
          } else if (r.receipt_status === 'REJECTED') {
            row.state = 'rejected';                // settled: the server said no
          } else {
            row.state = 'acked';                   // ACCEPTED / ALREADY_RECEIVED / PENDING_MATCH / NEEDS_REVIEW
          }
          store.put(row);
        };
      });
    });
  }

  /* Housekeeping: drop answered events older than `days`. Pending events are
     never touched, whatever their age. */
  function prune(days) {
    var cutoff = Date.now() - days * 86400000;
    return tx(['outbox'], 'readwrite', function (t) {
      t.objectStore('outbox').openCursor().onsuccess = function (e) {
        var c = e.target.result;
        if (!c) return;
        var v = c.value;
        if (v.state !== 'pending' && (v.answered_at || v.created_at) < cutoff) c.delete();
        c.continue();
      };
    });
  }

  function dismiss(uuid) {
    return tx(['outbox'], 'readwrite', function (t) {
      var s = t.objectStore('outbox');
      s.get(uuid).onsuccess = function (e) {
        var row = e.target.result;
        // Only a settled refusal can be cleared from view. A pending event
        // cannot be dismissed: that would be deleting an unsent scan.
        if (row && row.state === 'rejected') { row.state = 'acked'; row.dismissed = true; s.put(row); }
      };
    });
  }

  // ------------------------------------------------- cached lookup data
  function replaceAll(storeName, rows) {
    return tx([storeName], 'readwrite', function (t) {
      var s = t.objectStore(storeName);
      s.clear();
      (rows || []).forEach(function (r) { s.put(r); });
    });
  }
  function getAll(storeName) {
    var rows = [];
    return tx([storeName], 'readonly', function (t) {
      t.objectStore(storeName).openCursor().onsuccess = function (e) {
        var c = e.target.result;
        if (c) { rows.push(c.value); c.continue(); }
      };
    }).then(function () { return rows; });
  }
  function getOne(storeName, key) {
    var row;
    return tx([storeName], 'readonly', function (t) {
      t.objectStore(storeName).get(key).onsuccess = function (e) { row = e.target.result; };
    }).then(function () { return row; });
  }
  function put(storeName, row) {
    return tx([storeName], 'readwrite', function (t) { t.objectStore(storeName).put(row); }).then(function () { return row; });
  }
  /* Drop flags that are completely finished and older than `days`. A flag
     still waiting to send, or with a still waiting to send, is never touched. */
  function pruneFlags(days) {
    var cutoff = Date.now() - days * 86400000;
    return tx(['flags'], 'readwrite', function (t) {
      t.objectStore('flags').openCursor().onsuccess = function (e) {
        var c = e.target.result;
        if (!c) return;
        var v = c.value;
        if (v.state !== 'pending' && v.photo_state !== 'pending' && (v.answered_at || v.created_at) < cutoff) c.delete();
        c.continue();
      };
    });
  }
  function removeOne(storeName, key) {
    return tx([storeName], 'readwrite', function (t) { t.objectStore(storeName).delete(key); });
  }

  global.AttDB = {
    open: open, getMeta: getMeta, setMeta: setMeta,
    enqueue: enqueue, allOutbox: allOutbox, pending: pending,
    applyReceipts: applyReceipts, prune: prune, dismiss: dismiss,
    replaceAll: replaceAll, getAll: getAll, getOne: getOne, removeOne: removeOne, put: put, pruneFlags: pruneFlags
  };
})(self);
