# Voter Radius Lookup Tool

## Setup Instructions

1. **Install dependencies via Composer**  
   Run this in the project directory:
   ```bash
   composer install
   ```

2. **Create your `.env` file**  
   Copy `.env.example` and fill in your real credentials:
   ```bash
   cp .env.example .env
   ```

3. **Make sure the web server has access to `vendor/`**  
   If deploying, include the `vendor/` folder or run `composer install` on the server.

## Features

- Securely loads database credentials from `.env`
- Uses Leaflet.js to map voters within a radius
- Connects to geocoded address and voter databases
- Displays a "Searching..." indicator when form is submitted

## Performance & Debug

- Geo prefilter uses `MBRContains` on `geocode_db.geocoded_addresses.location` with a SPATIAL INDEX, then refines radius via `ST_Distance_Sphere` (miles).
- VAT join strategies (choose fastest for your DB):
  - Default: `vm_in` (filters by `vm.voter_addr_id IN (...)` and joins demographics by primary key).
  - Alternatives: `in` (filters by `mva.id IN (...)`) and `derived` (derived id list). Toggle with `?vat_strategy=vm_in|in|derived`.
- Debug timing/panels: add `?debug=1` to see SQL, timings, and EXPLAIN plans.

### Caching (geocode_db.cached_voters)

- The app caches voter rows by `(county, address_id, voter_id)` and reuses them for later searches.
- TTL: configurable via `.env` `CACHE_TTL_DAYS` (default 30). Cache reads require `updated_at >= NOW() - INTERVAL CACHE_TTL_DAYS DAY`.
- Recommended TTL: set `CACHE_TTL_DAYS=45` to align with monthly-but-variable source updates; stale areas quietly refresh on access.
- Forced refresh: add `?refresh=1` to bypass cache for a single search and refresh cache for those addresses.
- Party-aware: cache respects party filter (ALL returns all parties; party-specific searches only reuse rows for that party).

Schema (auto-created):

```
CREATE TABLE cached_voters (
  county CHAR(3) NOT NULL,
  address_id INT NOT NULL,
  voter_id INT NOT NULL,
  voter_name VARCHAR(100),
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  email_address VARCHAR(100),
  phone_number VARCHAR(20),
  birth_date DATE,
  party CHAR(10),
  voter_address TEXT,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (county, address_id, voter_id),
  INDEX idx_addr (address_id),
  INDEX idx_party (party)
);
```

Pre-populating the cache (optional):

- Use the app with `?refresh=1` over the area to warm the cache automatically.
- Or, if cross-DB privileges allow, run a direct insert from VAT:

```
INSERT INTO geocode_db.cached_voters (
  county, address_id, voter_id, voter_name, first_name, last_name,
  email_address, phone_number, birth_date, party, voter_address
)
SELECT vm.county, mva.id, vm.voter_id,
       d.voter_name, d.first_name, d.last_name,
       d.email_address, d.phone_number, d.birth_date,
       vm.party,
       CONCAT_WS(CHAR(10), mva.street_address, TRIM(CONCAT_WS(' ', mva.address_line2, mva.apt_number)))
FROM fddc_vat_sql.voter_master vm
JOIN fddc_vat_sql.master_voter_address mva ON vm.voter_addr_id = mva.id
JOIN fddc_vat_sql.demographics d ON d.id = vm.demographics_id AND d.county = vm.county
WHERE vm.county = 'VOL' AND vm.exp_date = '2100-12-31' AND mva.id IN (/* address ids */);
```

If cross-DB writes are not allowed, a small CLI script can batch-read from VAT and insert into `cached_voters`; ask if you want this added.

### CLI: Warm the Cache

Run a CLI helper to pre-populate `cached_voters` for a county and a set of addresses. This is optional and not required for normal use — the app refreshes stale entries on access based on TTL. Consider warming only if you need near-instant results over large areas.

- Usage:
  - By address ids: `php bin/warm_cache.php --county=VOL --address-ids=7544950,7545422 --party=ALL`
  - From file: `php bin/warm_cache.php --county=VOL --address-id-file=ids.txt`
  - From bounding box: `php bin/warm_cache.php --county=VOL --bbox=28.93,-81.24,28.95,-81.22`
  - From address + radius(mi): `php bin/warm_cache.php --county=VOL --from-address="1397 Winterville Street Deltona FL 32725" --radius=0.1`
  - Common flags: `--strategy=vm_in|in|derived` (default vm_in), `--chunk-size=200`, `--respect-ttl=1` (only fetch misses), `--dry-run=1`

- Notes:
  - Uses the same DB connections as the app (from `.env`).
  - County is required and should match VAT partitions (e.g., VOL).
  - With `--respect-ttl`, it skips ids already fresh per `CACHE_TTL_DAYS`.
  - Without it, it refreshes the cache for all provided ids.

You can also run via Composer or Makefile:

- Composer script:
  - composer warm-cache -- --county=VOL --address-ids=7544950,7545422 --party=ALL
- Makefile target:
  - make warm-cache ARGS="--county=VOL --from-address='1397 Winterville Street Deltona FL 32725' --radius=0.1"

#### Which Runner Should I Use?

- `bin/warm_cache.php` (the script)
  - This is the underlying PHP script that does the work. You can always call it directly: `php bin/warm_cache.php ...`.
  - Use this if you prefer plain PHP commands or are running in environments without Composer or Make.

- Composer alias (`composer warm-cache`)
  - A convenience alias that invokes the same script via Composer. Pass script arguments after `--`.
  - Use if you already use Composer and want a memorable command integrated with the project.

- Makefile target (`make warm-cache`)
  - Another convenience wrapper that forwards `ARGS` to the script. Lets you override which PHP binary to use.
  - Use if you like `make` workflows or want short commands for common recipes.

Important: You only need one of these. All three run the same underlying logic; they are just different ways to execute the same script.

CLI options (summary)
- `--county=XXX` (required): 3-letter county code matching VAT partitions (e.g., VOL).
- `--party=ALL|DEM|REP|NPA` (optional): filter party; default ALL.
- `--address-ids=1,2,3` (optional): comma-separated address_id list.
- `--address-id-file=ids.txt` (optional): file with one address_id per line.
- `--bbox=latMin,lonMin,latMax,lonMax` (optional): select addresses in a bounding box.
- `--from-address="..." --radius=0.1` (optional): geocode a point then select addresses within radius (miles).
- `--chunk-size=200` (optional): size of IN list batches to VAT; default 200.
- `--strategy=vm_in|in|derived` (optional): VAT strategy; default vm_in.
- `--respect-ttl=1` (optional): only fetch cache misses fresher than `CACHE_TTL_DAYS`.
- `--dry-run=1` (optional): do not write cache (for timing/testing).

Common recipes
- Warm by ids (Composer): `composer warm-cache -- --county=VOL --address-ids=7544950,7545422`
- Warm by file (Make): `make warm-cache ARGS="--county=VOL --address-id-file=ids.txt"`
- Warm by address + radius (PHP): `php bin/warm_cache.php --county=VOL --from-address="1397 Winterville Street Deltona FL 32725" --radius=0.1`

### Common Warm Recipes

The cache warmer accepts a set of `address_id`s. Below are practical ways to generate them for different scopes. Use whichever runner you prefer (Composer, Make, or direct PHP) — they all accept the same arguments.

1) Neighborhood or small area
- From an address and a radius (miles):
  - `composer warm-cache -- --county=VOL --from-address="1397 Winterville Street Deltona FL 32725" --radius=0.5`
  - Good for quickly warming around a specific point of interest.

2) City-wide (approximate)
- Using an approximate bounding box around the city:
  - `make warm-cache ARGS="--county=VOL --bbox=28.90,-81.30,29.00,-81.20"`
  - Adjust lat/lon to fit your city. You can also run several overlapping bboxes if the city is irregular.
- OR using a central address and a larger radius:
  - `php bin/warm_cache.php --county=VOL --from-address="Deltona City Hall" --radius=5.0`

3) ZIP code
- If `full_address` contains ZIP codes, you can export `address_id`s using SQL and feed them to the warmer:
  - Export ids with MySQL (example pattern; adjust to your data):
    - `mysql -h $GEO_DB_HOST -P $GEO_DB_PORT -u $GEO_DB_USER -p$GEO_DB_PASS $GEO_DB_NAME -N -e "SELECT address_id FROM geocoded_addresses WHERE full_address RLIKE ' 32725$'" > ids.txt`
  - Warm from the file:
    - `composer warm-cache -- --county=VOL --address-id-file=ids.txt`
  - Note: Relying on `full_address` format can be brittle; prefer spatial/boundary-based selection if possible.

4) Whole county (caution: heavy)
- Recommended only if you truly need it. Generate a file of `address_id`s that fall inside the county boundary, then warm in batches:
  - If you maintain county polygons elsewhere, precompute the list and save to `ids.txt`.
  - Warm with respect to TTL (skip fresh rows):
    - `make warm-cache ARGS="--county=VOL --address-id-file=ids.txt --respect-ttl=1 --chunk-size=500"`
  - Without TTL (force refresh):
    - `make warm-cache ARGS="--county=VOL --address-id-file=ids.txt --chunk-size=500"`
  - Tip: Split very large id files into smaller chunks and run sequentially to reduce load.

General tips
- Start with smaller areas to validate speed and correctness.
- Use `--respect-ttl=1` for periodic re-warms (e.g., weekly) so only stale entries are refetched.
- Increase `--chunk-size` (e.g., 500) if your VAT server handles larger IN lists efficiently; reduce it if latency per query grows.

Optional background cadence (future)
- If needed later, schedule a light monthly warm around the middle of the month using `--respect-ttl=1`, plus a weekly safety sweep. Scope to a few city/ZIP bboxes rather than entire counties to keep load low. With `CACHE_TTL_DAYS=45`, most weeks will be a no-op.



---

## 🗃️ Database Schema Requirements

> ⚠️ These are **minimal table definitions** required for this app to function.  
> In the current development setup, data is distributed across **two databases** (`geocode_db` and `voter_data`).  
> This is part of an exploratory process to integrate multiple data sources during development.

---

### 📍 `geocode_db.geocoded_addresses`

Used to store geocoded addresses for radius-based spatial lookup.

```sql
CREATE TABLE geocoded_addresses (
    address_id INT PRIMARY KEY,
    lat DECIMAL(10, 7),
    lon DECIMAL(10, 7),
    full_address VARCHAR(255),
    location POINT GENERATED ALWAYS AS (POINT(lon, lat)) STORED,
    SPATIAL INDEX (location)
);
```

- Requires MySQL 8.0+ for spatial indexing and `ST_Distance_Sphere`.

---

### 🗳️ `voter_data.voter_master`

Stores core voter registration metadata.

```sql
CREATE TABLE voter_master (
    voter_id VARCHAR(20) PRIMARY KEY,
    voter_addr_id INT,
    county VARCHAR(10),
    exp_date DATE,
    party VARCHAR(10),
    INDEX (county),
    INDEX (exp_date)
);
```

---

### 👤 `voter_data.demographics`

Contains contact and demographic data for voters.

```sql
CREATE TABLE demographics (
    voter_id VARCHAR(20) PRIMARY KEY,
    voter_name VARCHAR(100),
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    email_address VARCHAR(100),
    phone_number VARCHAR(20),
    birth_date DATE,
    party VARCHAR(10)
);
```

---

### 🏠 `voter_data.master_voter_address`

Links voter IDs to physical addresses.

```sql
CREATE TABLE master_voter_address (
    id INT PRIMARY KEY,
    street_address VARCHAR(255),
    address_line2 VARCHAR(100),
    apt_number VARCHAR(20)
);
```

---

### 🔗 Query Relationships & Logic

- Joins:
  - `voter_master.voter_addr_id = master_voter_address.id`
  - `voter_master.voter_id = demographics.voter_id`
- Filters:
  - `vm.county = ?`
  - `vm.exp_date = '2100-12-31'`
  - `mva.id IN (?, ?, ...)`
- Distance logic:
  ```sql
  ST_Distance_Sphere(POINT(lon, lat), POINT(:lng, :lat)) / 1609.34
  ```
