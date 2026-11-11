// Static/sandboxed verification only: no requests, sockets or external dispatch.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const collection = JSON.parse(fs.readFileSync(`${__dirname}/../postman/SANAD-Phase-6.postman_collection.json`, 'utf8'));
for (const variable of collection.variable) {
  if (/token|secret/i.test(variable.key)) assert.equal(variable.value, '');
}
const requests = collection.item.flatMap(folder => folder.item);
assert.ok(requests.length >= 24);
const scripts = [...collection.event, ...requests.flatMap(item => item.event ?? [])];
for (const event of scripts) new vm.Script(event.script.exec.join('\n'));
const guard = collection.event.find(event => event.listen === 'prerequest').script.exec.join('\n');
function allowed(baseUrl, token = 'local-static-test-token', patient = '123', method = 'GET', key = '') {
  let skipped = false;
  const variables = {base_url: baseUrl, patient_token: token, caregiver_token: token, unrelated_token: token, patient_id: patient, idempotency_key: key};
  const pm = {variables: {get: key => variables[key]}, request: {method}, execution: {skipRequest() { skipped = true; }}};
  try { vm.runInNewContext(guard, {pm, URL}); return !skipped; }
  catch { assert.equal(skipped, true); return false; }
}
for (const url of ['http://127.0.0.1:8000/api/v1', 'http://localhost:8000/api/v1', 'http://[::1]:8000/api/v1']) assert.equal(allowed(url), true);
for (const url of ['https://example.com/api/v1', 'http://127.0.0.1.evil.test/api/v1', 'http://2130706433/api/v1', 'http://user@localhost/api/v1', 'http://localhost/api/v1?x=1', 'http://localhost/api', 'invalid']) assert.equal(allowed(url), false);
assert.equal(allowed('http://localhost/api/v1', ''), false);
assert.equal(allowed('http://localhost/api/v1', 'token', ''), false);
assert.equal(allowed('http://localhost/api/v1', 'token', '123', 'POST', ''), false);
assert.equal(allowed('http://localhost/api/v1', 'token', '123', 'POST', '12345678-1234-4234-a234-123456789012'), true);
for (const item of requests) {
  assert.ok(item.request.url.startsWith('{{base_url}}/patients/{{patient_id}}/'));
  if (item.request.body) JSON.parse(item.request.body.raw);
  assert.ok(item.request.auth.bearer[0].value.includes('_token}}'));
}
console.log(`Phase 6 Postman: ${requests.length} requests, JSON/scripts/blank secrets/loopback and mutation guards passed; no HTTP sent.`);
