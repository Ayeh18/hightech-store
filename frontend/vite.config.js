import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// The browser only ever talks to the Vite dev server (same origin), which forwards API calls
// to Laravel. Same origin is what lets Sanctum's cookie-based SPA authentication work.
const apiTarget = process.env.VITE_API_PROXY_TARGET || 'http://localhost:8000'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    proxy: {
      '/api': { target: apiTarget, changeOrigin: false },
      '/sanctum': { target: apiTarget, changeOrigin: false },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.js'],
  },
})
