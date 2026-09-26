import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// The service worker in src/sw.ts is built separately and type-checked by
// tsconfig.worker.json. Wiring it into the build (and the Playwright offline
// test that exercises it) is Phase 1 work.
//
// The API target is configurable because port 8000 is a popular squat: on a
// machine where something else already holds it, requests silently reach the
// wrong application and the failure looks like a bug in this one.
const apiTarget = process.env.DIALYSIS_API_URL ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: apiTarget,
        changeOrigin: true,
      },
    },
  },
})
