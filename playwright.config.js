const fs = require('fs');
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

function resolveStorageState() {
  if (process.env.E2E_STORAGE_STATE) {
    const resolved = path.resolve(process.env.E2E_STORAGE_STATE);
    return fs.existsSync(resolved) ? resolved : undefined;
  }
  const autoPath = path.join(__dirname, 'e2e', '.auth', 'storage-state.json');
  return fs.existsSync(autoPath) ? autoPath : undefined;
}

const baseURL = (process.env.E2E_BASE || process.env.BASE_URL || process.env.NC_BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');

module.exports = defineConfig({
  globalSetup: require.resolve('./e2e/global-setup.js'),
  testDir: './e2e',
  globalTeardown: './e2e/global-teardown.mjs',

  timeout: 60_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL,
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // Staff/admin context. Guest-facing specs stay unauthenticated.
    storageState: resolveStorageState(),
  },
  projects: [
    {
      name: 'chromium-1280',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
    },
  ],
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
});
