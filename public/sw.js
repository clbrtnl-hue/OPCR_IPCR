const CACHE = "occ-pms-v3";

self.addEventListener("install", () => {
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener("fetch", (event) => {
    const { request } = event;

    if (request.method !== "GET") {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin || request.mode === "navigate" || url.pathname.startsWith("/api")) {
        return;
    }

    const cacheable = url.pathname.startsWith("/build/") || url.pathname.startsWith("/images/");

    if (!cacheable) {
        return;
    }

    event.respondWith(
        caches.open(CACHE).then(async (cache) => {
            const cached = await cache.match(request);
            const fetched = fetch(request)
                .then((response) => {
                    if (response.ok) {
                        cache.put(request, response.clone());
                    }

                    return response;
                })
                .catch(() => cached);

            return cached || fetched;
        })
    );
});
