/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    // API とクッキー認証を同一オリジンで扱うため、Laravel（Sail）へ転送する
    proxy: {
      '/api': 'http://localhost',
      '/sanctum': 'http://localhost',
    },
  },
  test: {
    // document.cookie などブラウザの API を使うため、jsdom 上でテストする
    environment: 'jsdom',
  },
})
