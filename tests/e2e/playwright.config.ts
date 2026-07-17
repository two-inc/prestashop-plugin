import { defineConfig } from "@playwright/test";

import { STORE_URL } from "./config.js";

export default defineConfig({
  testDir: "./tests",
  timeout: 180_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 1,
  use: {
    baseURL: STORE_URL,
    viewport: { width: 1280, height: 720 },
    actionTimeout: 15_000,
    trace: "retain-on-failure",
    video: "retain-on-failure"
  },
  projects: [
    {
      name: "chromium",
      use: { browserName: "chromium" }
    }
  ]
});
