// Static/sandboxed collection verification. Never makes HTTP requests.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const collection = JSON.parse(fs.readFileSync(`${__dirname}/../postman/SANAD-Phase-3.postman_collection.json`, 'utf8'));
for (const variable of collection.variable) {
  if (variable.key.includes('token')) assert.equal(variable.value, '');
}
const scripts = [...collection.event, ...collection.item.flatMap(item => item.event ?? [])];
for (const event of scripts) new vm.Script(event.script.exec.join('\n'));
const guard = collection.event[0].script.exec.join('\n');
function accepted(origin, token = 'local-test-token') {
  let skipped = false;
  const pm = {variables: {get: key => key === 'base_url' ? origin : token},
    info: {requestName: 'Patient: Static verification'}, execution: {skipRequest() { skipped = true; }}};
  try { vm.runInNewContext(guard, {pm, URL}); return !skipped; }
  catch { assert.equal(skipped, true); return false; }
}
for (const origin of ['http://127.0.0.1:8000', 'http://localhost:8000', 'http://[::1]:8000']) assert.equal(accepted(origin), true);
for (const origin of ['https://example.com', 'http://127.0.0.1.evil.test', 'http://user@localhost:8000', 'http://localhost:8000/api', 'http://localhost:8000?token=x', 'http://2130706433:8000', 'invalid']) assert.equal(accepted(origin), false);
assert.equal(accepted('http://localhost:8000', ''), false);
assert.equal(collection.item.length, 13);
const slotsScript = collection.item.find(item => item.name === 'Patient: Live links and slots').event[0].script.exec.join('\n');
function checkSlots(slots) {
  const pm = {test: (_name, fn) => fn(), response: {json: () => ({data: {slots}})},
    expect: value => ({to: {eql: expected => assert.deepEqual(value, expected),
      be: {within: (minimum, maximum) => assert.ok(value >= minimum && value <= maximum)}}})};
  vm.runInNewContext(slotsScript, {pm});
}
checkSlots({maximum: 2, occupied: 2, available: 0});
checkSlots({maximum: 2, occupied: 0, available: 2});
assert.throws(() => checkSlots({maximum: 3, occupied: 2, available: 1}));
assert.throws(() => checkSlots({maximum: 2, occupied: 1, available: 2}));
console.log('Phase 3 Postman: JSON, 13 requests, all scripts, two-slot assertions, blank secrets and local-origin guards passed; no HTTP sent.');
