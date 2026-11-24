import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor


IP = "10.1.33.170"

URLS = [
    f"http://{IP}:8001/api/vehicules",
    f"http://{IP}:8002/chauffeurs",
    f"http://{IP}:8003/carburants",
    f"http://{IP}:8004/api/health",
]

def hit(url: str) -> tuple[str, int, float]:
    start = time.perf_counter()
    try:
        req = urllib.request.Request(url, headers={"Accept": "application/json"})
        with urllib.request.urlopen(req, timeout=5) as resp:
            status = resp.status
    except Exception:
        status = 0
    elapsed_ms = (time.perf_counter() - start) * 1000
    return url, status, elapsed_ms


def main(total_requests: int = 30, workers: int = 10) -> None:
    tasks = [URLS[i % len(URLS)] for i in range(total_requests)]
    results = []
    with ThreadPoolExecutor(max_workers=workers) as pool:
        for res in pool.map(hit, tasks):
            results.append(res)

    for url in URLS:
        sub = [r for r in results if r[0] == url]
        errs = sum(1 for r in sub if r[1] == 0 or r[1] >= 500)
        print(f"  {url} -> {len(sub)} req | {errs} err 5xx/reseau | codes: {sorted({r[1] for r in sub})}")

    times = [r[2] for r in results]
    errors = sum(1 for r in results if r[1] == 0 or r[1] >= 500)
    print(f"Requetes: {len(results)} | Erreurs 5xx/reseau: {errors} | Moyenne: {sum(times)/len(times):.1f} ms | Max: {max(times):.1f} ms")
if __name__ == "__main__":
    main()