/// <reference lib="webworker" />
// Type-checked with tsconfig.worker.json (lib: WebWorker, NOT DOM). A service
// worker and a window have incompatible globals; one tsconfig cannot type both.
export {}
declare const self: ServiceWorkerGlobalScope & typeof globalThis

/**
 * Service worker for the bedside tablet.
 *
 * Caching policy is deliberately asymmetric:
 *   - the app shell is precached and served cache-first, so the tablet boots
 *     with no network at all;
 *   - GET /api is network-first with a cache fallback, because stale clinical
 *     data is dangerous but no data is worse;
 *   - POST /api is NEVER cached. Writes go through the outbox, never the SW.
 */
const SHELL = 'shell-v1'
const API_CACHE = 'api-v1'
const SHELL_ASSETS = ['/', '/index.html', '/manifest.webmanifest', '/offline.html']

self.addEventListener('install', (event: ExtendableEvent) => {
  event.waitUntil(caches.open(SHELL).then((c) => c.addAll(SHELL_ASSETS)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event: ExtendableEvent) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== SHELL && k !== API_CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event: FetchEvent) => {
  const url = new URL(event.request.url)

  if (event.request.method !== 'GET') return // writes belong to the outbox

  if (url.pathname.startsWith('/api/')) {
    event.respondWith(networkFirst(event.request))
    return
  }

  event.respondWith(cacheFirst(event.request))
})

async function networkFirst(request: Request): Promise<Response> {
  try {
    const response = await fetch(request)
    if (response.ok) {
      const cache = await caches.open(API_CACHE)
      await cache.put(request, response.clone())
    }
    return response
  } catch {
    const cached = await caches.match(request)
    if (cached) {
      // Mark it so the UI can show an "as of HH:MM" banner rather than
      // presenting stale clinical data as current.
      const headers = new Headers(cached.headers)
      headers.set('X-From-Cache', '1')
      return new Response(cached.body, { status: cached.status, headers })
    }
    return new Response(JSON.stringify({ offline: true }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' },
    })
  }
}

async function cacheFirst(request: Request): Promise<Response> {
  const cached = await caches.match(request)
  if (cached) return cached

  try {
    return await fetch(request)
  } catch {
    return (await caches.match('/offline.html')) ?? new Response('Offline', { status: 503 })
  }
}

// Background Sync: fires when connectivity returns, even if the tab is closed.
interface SyncEvent extends ExtendableEvent {
  readonly tag: string
}

self.addEventListener('sync', ((event: SyncEvent) => {
  if (event.tag === 'outbox-flush') {
    event.waitUntil(
      self.clients.matchAll().then((clients) => {
        clients.forEach((c) => c.postMessage({ type: 'flush-outbox' }))
      }),
    )
  }
}) as EventListener)
