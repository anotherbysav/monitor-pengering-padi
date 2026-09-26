import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { rm, mkdir } from 'node:fs/promises'

const target = process.env.VITE_API_TARGET || 'http://127.0.0.1:8000'

/**
 * Bersihkan hanya folder public/assets sebelum build.
 *
 * outDir menunjuk ke public/ yang juga memuat index.php, jadi
 * emptyOutDir harus tetap false. Tanpa pembersihan ini, hash aset lama
 * menumpuk setiap build sehingga folder public/ membengkak.
 *
 * Penting: JANGAN pernah memindaikan aset dari index.html untuk deciding
 * apa yang boleh dihapus. Chunk route di-import dinamis dari bundle
 * utama, sehingga tidak muncul di index.html.
 */
function cleanAssetsDir() {
  const assetsDir = fileURLToPath(new URL('../public/assets', import.meta.url))
  return {
    name: 'clean-assets-dir',
    apply: 'build',
    async buildStart() {
      await rm(assetsDir, { recursive: true, force: true })
      await mkdir(assetsDir, { recursive: true })
    }
  }
}

export default defineConfig({
  plugins: [vue(), cleanAssetsDir()],
  // Base path untuk build. Untuk GitHub Pages (subfolder) set lewat env:
  //   VITE_BASE_PATH=/monitor-pengering-padi/ npm run build
  // Tanpa env -> '/' supaya build lokal/PHP tetap jalan seperti sebelumnya.
  base: process.env.VITE_BASE_PATH || '/',
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  server: {
    // Dipaksa IPv4: tanpa ini Vite hanya listen di ::1 sehingga
    // http://127.0.0.1:5173 gagal dibuka di browser.
    host: '127.0.0.1',
    port: 5173,
    proxy: {
      // Development: arahkan /api ke backend PHP
      '/api': {
        target,
        changeOrigin: true
      }
    }
  },
  build: {
    // Build di dalam folder public yang sama dengan router PHP.
    // JANGAN memakai emptyOutDir: true karena akan menghapus public/index.php.
    outDir: '../public',
    emptyOutDir: false,
    assetsDir: 'assets',
    chunkSizeWarningLimit: 900
  }
})
