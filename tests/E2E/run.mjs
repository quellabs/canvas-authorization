import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { createWriteStream } from 'node:fs';
import { copyFile, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { get as httpGet } from 'node:http';
import { get as httpsGet } from 'node:https';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { generate } from 'selfsigned';

const e2eDirectory = fileURLToPath(new URL('.', import.meta.url));
const fixture = join(e2eDirectory, 'app');
const app = join(e2eDirectory, '.app-run');
const runtime = join(e2eDirectory, '.runtime');
const certificatePath = join(runtime, 'provider-cert.pem');
const privateKeyPath = join(runtime, 'provider-key.pem');
const databaseName = `canvas_authorization_e2e_${randomBytes(6).toString('hex')}`;
const environment = { ...process.env, E2E_DB_NAME: databaseName, E2E_TLS_CERT: certificatePath, E2E_TLS_KEY: privateKeyPath };
const fixtureFiles = [
  'composer.json',
  'composer.lock',
  'config/app.php',
  'config/database.php',
  'config/smarty.php',
  'public/index.php',
  'public/router.php',
  'src/Controllers/TestStatusController.php',
  'bin/database.php',
  'bin/identity-status.php',
];

/** Run a command to completion in the disposable application. */
function command(executable, args, cwd, env = environment) {
  return new Promise((done, reject) => {
    const child = spawn(executable, args, { cwd, env, stdio: 'inherit', shell: process.platform === 'win32' && executable === 'composer' });
    child.once('error', reject);
    child.once('exit', (code) => code === 0 ? done() : reject(new Error(`${executable} exited with code ${code}`)));
  });
}

/** Wait until a server accepts requests at its health URL. */
async function waitFor(url, secure, processHandle) {
  const get = secure ? httpsGet : httpGet;
  for (let attempt = 0; attempt < 100; attempt++) {
    if (processHandle.exitCode !== null) throw new Error(`${url} server exited before it became ready`);
    try {
      await new Promise((done, reject) => {
        get(url, secure ? { rejectUnauthorized: false } : {}, (response) => {
          response.resume();
          response.statusCode < 500 ? done() : reject(new Error(`HTTP ${response.statusCode}`));
        }).once('error', reject);
      });
      return;
    } catch {
      await new Promise((done) => setTimeout(done, 200));
    }
  }
  throw new Error(`${url} did not become ready`);
}

/** Copy only committed fixture inputs into a fresh application directory. */
async function prepareApp() {
  await rm(app, { recursive: true, force: true });
  for (const relative of fixtureFiles) {
    const target = join(app, relative);
    await mkdir(resolve(target, '..'), { recursive: true });
    await copyFile(join(fixture, relative), target);
  }
}

/** Build a local TLS endpoint trusted by the PHP client for this run. */
async function prepareCertificate() {
  await mkdir(runtime, { recursive: true });
  const certificate = await generate([{ name: 'commonName', value: '127.0.0.1' }], {
    days: 1,
    keySize: 2048,
    algorithm: 'sha256',
    extensions: [
      { name: 'basicConstraints', cA: true },
      { name: 'keyUsage', keyCertSign: true, digitalSignature: true, keyEncipherment: true },
      { name: 'subjectAltName', altNames: [{ type: 7, ip: '127.0.0.1' }] },
    ],
  });
  await writeFile(certificatePath, certificate.cert);
  await writeFile(privateKeyPath, certificate.private);
}

/** Start one server with its output recorded for failed test diagnosis. */
function server(executable, args, cwd, logName) {
  const log = createWriteStream(join(runtime, logName));
  const child = spawn(executable, args, { cwd, env: environment, stdio: ['ignore', 'pipe', 'pipe'] });
  child.stdout.pipe(log);
  child.stderr.pipe(log);
  return child;
}

let phpServer;
let oidcServer;
let databaseCreated = false;

try {
  await prepareApp();
  await prepareCertificate();
  await command('composer', ['install', '--no-interaction', '--prefer-dist'], app);
  await command('php', ['./vendor/bin/sculpt', 'install:auth-oidc'], app);
  await writeFile(join(app, 'config/oidc.local.php'), `<?php return [
    'enabled' => true,
    'issuer' => 'https://127.0.0.1:8443',
    'client_id' => 'canvas-e2e',
    'client_secret' => 'canvas-e2e-secret',
    'redirect_uri' => 'http://127.0.0.1:8000/auth/oidc/callback',
    'allow_private_network_endpoints' => true,
  ];\n`);
  await command('php', ['bin/database.php', 'create'], app);
  databaseCreated = true;
  await command('php', ['./vendor/bin/sculpt', 'make:migrations'], app);
  await command('php', ['./vendor/bin/sculpt', 'quel:migrate', '--force'], app);

  oidcServer = server(process.execPath, [join(e2eDirectory, 'provider.mjs')], e2eDirectory, 'provider.log');
  await waitFor('https://127.0.0.1:8443/.well-known/openid-configuration', true, oidcServer);
  phpServer = server('php', [
    '-d', `openssl.cafile=${certificatePath}`,
    '-d', `curl.cainfo=${certificatePath}`,
    '-S', '127.0.0.1:8000', '-t', 'public', 'public/router.php',
  ], app, 'php.log');
  await waitFor('http://127.0.0.1:8000/login', false, phpServer);
  await command(process.execPath, [join(e2eDirectory, 'node_modules/@playwright/test/cli.js'), 'test', '--config', join(e2eDirectory, 'playwright.config.mjs')], e2eDirectory);
} catch (error) {
  console.error(error);
  for (const name of ['provider.log', 'php.log']) {
    try { console.error(`${name}:\n${await readFile(join(runtime, name), 'utf8')}`); } catch { /* server did not start */ }
  }
  process.exitCode = 1;
} finally {
  phpServer?.kill();
  oidcServer?.kill();
  if (databaseCreated) {
    try { await command('php', ['bin/database.php', 'drop'], app); } catch (error) { console.error(error); process.exitCode = 1; }
  }
}
