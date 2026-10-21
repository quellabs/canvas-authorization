import { createServer } from 'node:https';
import { generateKeyPairSync, randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import Provider from 'oidc-provider';

const issuer = 'https://127.0.0.1:8443';
const { privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
const signingKey = {
  ...privateKey.export({ format: 'jwk' }),
  kid: 'canvas-e2e-rs256',
  alg: 'RS256',
  use: 'sig',
};

const provider = new Provider(issuer, {
  clients: [{
    client_id: 'canvas-e2e',
    client_secret: 'canvas-e2e-secret',
    redirect_uris: ['http://127.0.0.1:8000/auth/oidc/callback'],
    response_types: ['code'],
    grant_types: ['authorization_code'],
    token_endpoint_auth_method: 'client_secret_basic',
  }],
  cookies: { keys: [randomBytes(32).toString('hex')] },
  jwks: { keys: [signingKey] },
  pkce: { required: () => true },
  async findAccount(_ctx, id) {
    return { accountId: id, async claims() { return { sub: id }; } };
  },
});

createServer({
  key: readFileSync(process.env.E2E_TLS_KEY),
  cert: readFileSync(process.env.E2E_TLS_CERT),
}, provider.callback()).listen(8443, '127.0.0.1');
