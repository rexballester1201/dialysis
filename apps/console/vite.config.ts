import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Configurable for the same reason the bedside app's is: port 8000 is a popular
// squat, and when something else holds it the requests reach the wrong
// application and the failure looks like a bug in this one.
const apiTarget = process.env.DIALYSIS_API_URL ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5174,
    proxy: {
      '/api': {
        target: apiTarget,
        changeOrigin: true,
      },
    },
  },
})
